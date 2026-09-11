const { createServer } = require('http');
const { Server } = require('socket.io');
const Redis = require('ioredis');
require('dotenv').config();

const PORT = process.env.SOCKET_PORT || 3000;
const ALLOWED_ORIGINS = process.env.ALLOWED_ORIGINS
  ? process.env.ALLOWED_ORIGINS.split(',')
  : ['http://localhost:5173', 'http://localhost:3000'];

// Store connected users: { userId: socketId } (used by both Socket.io and HTTP broadcast)
const connectedUsers = new Map();

// Track recently delivered message IDs to prevent Redis duplicates
const recentlyDelivered = new Map(); // messageId -> timestamp
const DEDUP_TTL = 10000; // 10 seconds

function cleanupDedup() {
  const now = Date.now();
  for (const [id, ts] of recentlyDelivered) {
    if (now - ts > DEDUP_TTL) recentlyDelivered.delete(id);
  }
}
setInterval(cleanupDedup, 30000);

function emitMessageReceived(data) {
  const { recipientId, conversationId, message: msg, sender } = data;
  const messageId = msg?.id;

  // Dedup: skip if we already delivered this message directly from the send handler
  if (messageId && recentlyDelivered.has(messageId)) {
    return;
  }

  const recipientSocketId = connectedUsers.get(recipientId?.toString());
  if (recipientSocketId && io) {
    io.to(recipientSocketId).emit('message:received', {
      conversationId,
      message: msg,
      sender
    });
  }
}

// Create HTTP server (handles both Socket.io upgrade and POST /broadcast from Laravel when Redis is not used)
const httpServer = createServer((req, res) => {
  if (req.method === 'POST' && req.url === '/broadcast') {
    let body = '';
    req.on('data', (chunk) => { body += chunk; });
    req.on('end', () => {
      try {
        const data = JSON.parse(body || '{}');
        emitMessageReceived(data);
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ success: true }));
      } catch (e) {
        res.writeHead(400, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ success: false, error: e.message }));
      }
    });
    return;
  }
  res.writeHead(404);
  res.end();
});

// Create Socket.io server with CORS
const io = new Server(httpServer, {
  cors: {
    origin: ALLOWED_ORIGINS,
    methods: ['GET', 'POST'],
    credentials: true
  },
  transports: ['websocket', 'polling'],
  pingTimeout: 30000,
  pingInterval: 15000
});

// Redis pub/sub for horizontal scaling (optional but recommended for production)
let redis = null;
let redisSub = null;
let redisAvailable = false;

try {
  redis = new Redis({
    host: process.env.REDIS_HOST || '127.0.0.1',
    port: process.env.REDIS_PORT || 6379,
    retryStrategy: (times) => {
      if (times > 5) return null; // Stop retrying after 5 attempts
      const delay = Math.min(times * 50, 2000);
      return delay;
    },
    maxRetriesPerRequest: 3,
    lazyConnect: true,
  });

  redisSub = redis.duplicate();

  redis.connect().then(() => {
    redisAvailable = true;
    console.log('[Redis] Connected successfully');
  }).catch((err) => {
    console.warn('[Redis] Not available, running without Redis:', err.message);
    redisAvailable = false;
  });

  redisSub.connect().then(() => {
    // Subscribe to Redis channels
    ['chat:message', 'chat:read_receipt', 'chat:reaction', 'chat:events'].forEach((ch) => {
      redisSub.subscribe(ch, (err) => {
        if (err) console.error(`[Redis] Failed to subscribe to ${ch}:`, err.message);
        else console.log(`[Redis] Subscribed to ${ch}`);
      });
    });
  }).catch((err) => {
    console.warn('[Redis] Subscriber not available:', err.message);
  });
} catch (err) {
  console.warn('[Redis] Initialization failed, running without Redis:', err.message);
}

// Socket.io connection handler
io.on('connection', (socket) => {
  console.log(`[Socket.io] Client connected: ${socket.id}`);

  // Authenticate user
  socket.on('authenticate', (data) => {
    const { userId, token } = data;

    if (!userId || !token) {
      socket.emit('error', { message: 'Authentication failed: missing userId or token' });
      socket.disconnect();
      return;
    }

    // Store user connection
    connectedUsers.set(userId.toString(), socket.id);
    socket.userId = userId;
    socket.join(`user:${userId}`);

    console.log(`[Socket.io] User authenticated: ${userId} (socket: ${socket.id})`);

    // Emit success and list of online user IDs so client can show green dots
    const onlineUserIds = Array.from(connectedUsers.keys()).map(Number).filter(id => id !== Number(userId));
    socket.emit('authenticated', {
      success: true,
      userId,
      onlineUserIds,
      message: 'Successfully connected to chat server'
    });

    // Broadcast online status to others
    socket.broadcast.emit('user:online', { userId });
  });

  // Handle new message: persist via Laravel API, then emit DIRECTLY to recipient
  socket.on('message:send', async (data) => {
    const { conversationId, message, recipientId, token } = data;
    if (!socket.userId || !conversationId) {
      socket.emit('message:send:error', { message: 'Missing conversationId or not authenticated' });
      return;
    }

    const apiUrl = process.env.LARAVEL_API_URL || process.env.API_URL || 'http://localhost:8000';
    const url = `${apiUrl.replace(/\/$/, '')}/api/chat/conversations/${conversationId}/messages`;

    try {
      const res = await fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
        body: JSON.stringify({ message: message || '' }),
      });

      const body = await res.json().catch(() => ({}));

      if (!res.ok) {
        socket.emit('message:send:error', {
          message: body.message || 'Failed to send message',
          status: res.status,
        });
        return;
      }

      if (body.success && body.data) {
        // 1) Confirm to sender
        socket.emit('message:sent', { conversationId, message: body.data });

        // 2) Deliver DIRECTLY to recipient (no Redis round-trip needed)
        const messageId = body.data.id;
        if (recipientId) {
          // Build recipient-facing payload (is_mine = false)
          const recipientPayload = {
            ...body.data,
            is_mine: false,
            is_read: false,
            read_at: null,
          };

          const recipientSocketId = connectedUsers.get(recipientId.toString());
          if (recipientSocketId) {
            io.to(recipientSocketId).emit('message:received', {
              conversationId,
              message: recipientPayload,
            });
          }

          // Mark as delivered so Redis dedup skips this message
          if (messageId) {
            recentlyDelivered.set(messageId, Date.now());
          }
        }
      } else {
        socket.emit('message:send:error', { message: 'Invalid response from server' });
      }
    } catch (err) {
      console.error('[Socket.io] Laravel API error:', err.message);
      socket.emit('message:send:error', {
        message: err.message || 'Network error',
      });
    }
  });

  // Handle typing indicator
  socket.on('typing:start', (data) => {
    const { conversationId, recipientId } = data;
    const recipientSocketId = connectedUsers.get(recipientId?.toString());

    if (recipientSocketId) {
      io.to(recipientSocketId).emit('typing:status', {
        conversationId,
        userId: socket.userId,
        isTyping: true
      });
    }
  });

  socket.on('typing:stop', (data) => {
    const { conversationId, recipientId } = data;
    const recipientSocketId = connectedUsers.get(recipientId?.toString());

    if (recipientSocketId) {
      io.to(recipientSocketId).emit('typing:status', {
        conversationId,
        userId: socket.userId,
        isTyping: false
      });
    }
  });

  // Handle read receipts — emit to sender AND persist via API
  socket.on('message:read', async (data) => {
    const { conversationId, messageIds, senderId, token } = data;
    const senderSocketId = connectedUsers.get(senderId?.toString());

    // Immediately notify the sender in real-time
    if (senderSocketId) {
      io.to(senderSocketId).emit('message:read:confirmation', {
        conversationId,
        messageIds: Array.isArray(messageIds) ? messageIds : [messageIds],
        readBy: socket.userId
      });
    }

    // Also persist via API so offline users see read status when they reconnect
    if (token && conversationId) {
      const apiUrl = process.env.LARAVEL_API_URL || process.env.API_URL || 'http://localhost:8000';
      const url = `${apiUrl.replace(/\/$/, '')}/api/chat/conversations/${conversationId}/read`;
      try {
        await fetch(url, {
          method: 'PATCH',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            ...(token ? { Authorization: `Bearer ${token}` } : {}),
          },
        });
      } catch (err) {
        console.error('[Socket.io] Read receipt persist error:', err.message);
      }
    }
  });

  // Handle disconnection
  socket.on('disconnect', () => {
    if (socket.userId) {
      connectedUsers.delete(socket.userId.toString());
      socket.broadcast.emit('user:offline', { userId: socket.userId });
      console.log(`[Socket.io] User disconnected: ${socket.userId} (socket: ${socket.id})`);
    } else {
      console.log(`[Socket.io] Client disconnected: ${socket.id}`);
    }
  });

  // Handle errors
  socket.on('error', (error) => {
    console.error(`[Socket.io] Socket error:`, error);
  });
});

// Redis subscriber message handler
if (redisSub) {
  redisSub.on('message', (channel, message) => {
    try {
      const data = JSON.parse(message);

      if (channel === 'chat:message') {
        emitMessageReceived(data);
        return;
      }

      if (channel === 'chat:read_receipt') {
        const { senderId, conversationId, messageIds, readBy } = data;
        const senderSocketId = connectedUsers.get(senderId?.toString());
        if (senderSocketId) {
          io.to(senderSocketId).emit('message:read:confirmation', {
            conversationId,
            messageIds,
            readBy
          });
        }
        return;
      }

      if (channel === 'chat:reaction') {
        const { conversationId, messageId, reactions, recipientId } = data;
        const recipientSocketId = connectedUsers.get(recipientId?.toString());
        if (recipientSocketId) {
          io.to(recipientSocketId).emit('message:reaction', {
            conversationId,
            messageId,
            reactions
          });
        }
        return;
      }

      if (channel === 'chat:events') {
        const { type, userId } = data;
        if (type === 'user:online' || type === 'user:offline') {
          io.emit(type, { userId });
        }
      }
    } catch (error) {
      console.error('[Redis] Error parsing message:', error);
    }
  });
}

// Handle Redis errors
if (redis) {
  redis.on('error', (err) => {
    if (redisAvailable) {
      console.error('[Redis] Redis client error:', err.message);
      redisAvailable = false;
    }
  });
}

if (redisSub) {
  redisSub.on('error', (err) => {
    console.error('[Redis] Redis subscriber error:', err.message);
  });
}

// Start server
httpServer.listen(PORT, () => {
  console.log(`
========================================
  EventGenius Socket.io Server Running
  Port: ${PORT}
  Origins: ${ALLOWED_ORIGINS.join(', ')}
  Redis: ${redisAvailable ? 'Connected' : 'Not available (running without)'}
  Ready for WebSocket connections!
========================================
  `);
});

// Graceful shutdown
process.on('SIGTERM', () => {
  console.log('[Server] SIGTERM received, closing server...');
  httpServer.close(() => {
    console.log('[Server] Server closed');
    if (redis) redis.quit();
    if (redisSub) redisSub.quit();
    process.exit(0);
  });
});

process.on('SIGINT', () => {
  console.log('[Server] SIGINT received, closing server...');
  httpServer.close(() => {
    console.log('[Server] Server closed');
    if (redis) redis.quit();
    if (redisSub) redisSub.quit();
    process.exit(0);
  });
});
