# EventGenius review analyser

Offline sentiment + aspect analysis of customer reviews, run by Laravel (`reviews:analyze`
and the `AnalyzeReviews` queued job) through `analyze.py`. Nothing runs permanently: each call
starts Python, loads the model once (about 0.3 s, ~160 MB RAM, 1 thread), scores the batch and exits.
No network access is needed at runtime.

## What it does

* Splits each review (title counts half) into sentences and clauses (`but`, `lekin`, `magar`, `لیکن` …).
* Detects the language of each clause: Urdu script, Roman Urdu, or English.
* English → `Xenova/distilbert-base-uncased-finetuned-sst-2-english` (Apache-2.0, 8-bit ONNX, ~68 MB),
  blended with an English lexicon so neutral text ("okay", "fine for the price") is not forced to an extreme.
* Roman Urdu and Urdu → lexicon scorer with intensifiers (bohat/bahut/بہت, very…) and negation
  (nahi/nahin/نہیں/na…, checked before *and after* the word because Urdu puts it after).
* Aspects (keyword lexicons in English, Roman Urdu and Urdu): food, service, decor, value,
  cleanliness, parking, cooling, sound_av, location, management, power_backup.
  Aspect sentiment = mean sentiment of the clauses that mention it.
* Flags: `very_short`, `spam` (links, phone numbers, e-mails, promo words), `all_caps`,
  `rating_text_mismatch`. (`possible_duplicate` needs the whole review table and is added by Laravel.)

## Interface

```
echo '[{"id":1,"title":"Great","comment":"Khana bohat acha tha but parking was crowded","rating":4}]' \
  | /opt/eventgenius-ai/venv/bin/python -I -B /opt/eventgenius-ai/analyze.py
```
returns
```json
[{"id": 1, "sentiment": 0.41, "label": "positive", "confidence": 0.62, "language": "mixed",
  "aspects": {"food": {"sentiment": 0.83, "mentions": 1}, "parking": {"sentiment": -0.52, "mentions": 1}},
  "flags": [], "phrases": {"positive": ["Khana bohat acha tha"], "negative": ["parking was crowded"]},
  "model_version": "distilbert-sst2-int8+lex3"}]
```
`sentiment` is −1..1; `label` is positive (> 0.25), negative (< −0.25) or neutral.
If the model files are missing the script still answers using the lexicon only
(`model_version` = `lex3`) and prints a warning on stderr.

Other modes: `analyze.py --status` (JSON health check), `analyze.py --eval [-v]`
(accuracy per language on `eval_set.json`, hybrid vs. model alone).

## Install / reinstall

```
sudo ai/install.sh                          # → /opt/eventgenius-ai, downloads the model once
sudo ai/install.sh /opt/eventgenius-ai /path/to/model-dir   # use already-downloaded files
```
Layout: `venv/` (onnxruntime, tokenizers, numpy — see requirements.txt), `model/`
(`model_quantized.onnx`, `tokenizer.json`, `config.json`), `analyze.py`, `eval_set.json`, `README.md`.
Everything is owned by root and world-readable so `www-data` can run it.

After changing `analyze.py` or the model, bump `LEXICON_VERSION`/`MODEL_NAME` and re-analyse:
`php artisan reviews:analyze --all`.

Laravel finds the install through `REVIEW_AI_PATH` (default `/opt/eventgenius-ai`), see
`config/review_ai.php`.
