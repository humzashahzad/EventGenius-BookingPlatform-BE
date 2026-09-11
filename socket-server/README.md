# EventGenius Socket.io Server (WebSockets)

Real-time chat runs over **WebSockets** via this Node.js Socket.io server. The frontend connects here; Laravel pushes new messages to this server (via Redis or HTTP), and the server emits to the recipient’s socket.

## Run the server

```bash
cd socket-server
npm install
npm start
```

Default port: **3000**. Set `SOCKET_PORT` in `.env` to change it.

## Laravel connection

- **With Redis**: Laravel publishes to Redis channels (`chat:message`, etc.). This server subscribes and emits to the right user.
- **Without Redis**: Laravel sends `POST http://<SOCKET_IO_URL>/broadcast` with the same payload. This server’s HTTP handler receives it and emits `message:received` to the recipient.

In Laravel `.env`:

- `SOCKET_IO_URL=http://localhost:3000` (or your server URL, e.g. `http://10.189.174.153:3000`)

## Frontend

Set `VITE_SOCKET_URL` in the frontend `.env` to this server’s URL (e.g. `http://localhost:3000` or your LAN URL). The app connects on login and uses the socket for real-time messages, typing, read receipts, and online status.

## CORS

Set `ALLOWED_ORIGINS` in `.env` to allow your frontend origin(s), e.g.:

```
ALLOWED_ORIGINS=http://localhost:5173,http://10.189.174.153:5173
```
