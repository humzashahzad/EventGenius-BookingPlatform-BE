#!/usr/bin/env bash
# Installs (or reinstalls) the EventGenius review analyser.
#   sudo ai/install.sh [TARGET_DIR] [MODEL_SOURCE_DIR]
# TARGET_DIR defaults to /opt/eventgenius-ai. MODEL_SOURCE_DIR is a folder with
# model_quantized.onnx, tokenizer.json and config.json; without it the files are downloaded
# once from Hugging Face (Xenova/distilbert-base-uncased-finetuned-sst-2-english).
set -euo pipefail

TARGET="${1:-/opt/eventgenius-ai}"
MODEL_SRC="${2:-}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PY="${PYTHON:-python3}"
REPO="Xenova/distilbert-base-uncased-finetuned-sst-2-english"

mkdir -p "$TARGET/model"

if [ ! -x "$TARGET/venv/bin/python" ]; then
    "$PY" -m venv "$TARGET/venv"
fi
"$TARGET/venv/bin/pip" install --quiet --upgrade pip
"$TARGET/venv/bin/pip" install --quiet -r "$HERE/requirements.txt"

if [ -n "$MODEL_SRC" ]; then
    cp "$MODEL_SRC/model_quantized.onnx" "$MODEL_SRC/tokenizer.json" "$MODEL_SRC/config.json" "$TARGET/model/"
elif [ ! -f "$TARGET/model/model_quantized.onnx" ]; then
    base="https://huggingface.co/$REPO/resolve/main"
    curl -fsSL -o "$TARGET/model/model_quantized.onnx" "$base/onnx/model_quantized.onnx"
    curl -fsSL -o "$TARGET/model/tokenizer.json" "$base/tokenizer.json"
    curl -fsSL -o "$TARGET/model/config.json" "$base/config.json"
fi

install -m 0644 "$HERE/analyze.py" "$HERE/eval_set.json" "$HERE/README.md" "$HERE/requirements.txt" "$TARGET/"

# Readable (not writable) by the web user.
chown -R root:root "$TARGET"
chmod -R a+rX "$TARGET"

echo "Status:" && "$TARGET/venv/bin/python" -I -B "$TARGET/analyze.py" --status
echo "Evaluation:" && "$TARGET/venv/bin/python" -I -B "$TARGET/analyze.py" --eval
