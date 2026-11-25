#!/usr/bin/env python3
"""
EventGenius review analyser (offline, CPU only).

stdin : JSON list of {"id", "title", "comment", "rating"}
stdout: JSON list of {"id", "sentiment" (-1..1), "label", "confidence", "language",
                      "aspects": {name: {"sentiment", "mentions"}}, "flags": [...],
                      "phrases": {"positive": [...], "negative": [...]}, "model_version"}

Hybrid per sentence/clause:
  * English     -> DistilBERT SST-2 (8-bit ONNX), blended with an English lexicon (catches neutral text)
  * Roman Urdu  -> lexicon (intensifiers + negation), model only as a weak fallback
  * Urdu script -> lexicon
Aspects come from keyword lexicons (English + Roman Urdu + Urdu); an aspect's sentiment is the
mean sentiment of the clauses that mention it.

Modes:
  analyze.py            read stdin, write stdout
  analyze.py --eval     score the labelled evaluation set (eval_set.json next to this file)
  analyze.py --status   print model/runtime status as JSON
No network access is used at runtime; the model is loaded once per call with one thread.
"""
import json
import math
import os
import re
import sys
import time

HERE = os.path.dirname(os.path.abspath(__file__))
MODEL_DIR = os.environ.get("REVIEW_AI_MODEL_DIR", os.path.join(HERE, "model"))
MODEL_NAME = "distilbert-sst2-int8"
LEXICON_VERSION = "lex3"
MODEL_VERSION = MODEL_NAME + "+" + LEXICON_VERSION
BATCH = 16

os.environ.setdefault("OMP_NUM_THREADS", "1")
os.environ.setdefault("ORT_DISABLE_TELEMETRY", "1")  # onnxruntime >= 1.2x ships 1DS telemetry; keep it off
os.environ.setdefault("HF_HUB_OFFLINE", "1")
os.environ.setdefault("TOKENIZERS_PARALLELISM", "false")

# ── Lexicons ──────────────────────────────────────────────────────────────────
# polarity weights: 1 strong, 0.5-0.7 mild. Tokens are lower-cased (Urdu untouched).
POS = {
    # English
    "good": .7, "great": 1, "excellent": 1, "amazing": 1, "awesome": 1, "delicious": 1, "tasty": .9,
    "beautiful": 1, "stunning": 1, "lovely": .9, "nice": .6, "clean": .7, "spotless": 1, "polite": .8,
    "professional": .7, "friendly": .8, "helpful": .8, "responsive": .7, "perfect": 1, "perfectly": .8,
    "flawless": 1, "flawlessly": 1, "comfortable": .7, "smooth": .7, "recommended": .8, "recommend": .8,
    "love": .9, "loved": .9, "enjoyed": .8, "wonderful": 1, "fantastic": 1, "superb": 1, "impressive": .9,
    "crisp": .6, "spacious": .6, "easy": .5, "affordable": .6, "reasonable": .5, "organised": .6,
    "organized": .6, "praising": .8, "praised": .8, "happy": .7, "pleasant": .7, "elegant": .8,
    "gorgeous": 1, "outstanding": 1, "fresh": .5, "cooperative": .7, "courteous": .8, "worth": .6,
    "thanks": .4, "thank": .4, "best": .9, "exceeded": .8, "hassle-free": .8, "calmly": .4, "fun": .6,
    "well": .3, "glad": .6, "satisfied": .7, "unforgettable": .9, "special": .4, "promptly": .5,
    "quickly": .4, "instantly": .5, "comfortably": .6, 
    # Roman Urdu
    "acha": .7, "achha": .7, "achaa": .7, "acchha": .7, "achi": .7, "achhi": .7, "acchi": .7, "ache": .7,
    "achay": .7, "achhe": .7, "achay": .7, "umda": .9, "zabardast": 1, "zbrdst": 1, "zabardust": 1,
    "behtareen": 1, "behtreen": 1, "bahtareen": 1, "behtarin": 1, "kamaal": 1, "kamal": .9, "shandaar": 1,
    "shandar": 1, "khoobsurat": .9, "khubsurat": .9, "khush": .7, "mazedar": 1, "mazedaar": 1, "maza": .7,
    "mazay": .7, "mazaa": .7, "lazeez": 1, "lajawab": 1, "lajawaab": 1, "saaf": .7, "theek": .25,
    "thik": .25, "pasand": .7, "pyara": .8, "pyari": .8, "payara": .8, "tameezdar": .8, "mehrban": .7,
    "shukriya": .5, "wah": .8, "waah": .8, "aala": .9, "khoob": .7, "sasta": .4, "munasib": .5,
    "araam": .5, "aram": .5, "sukoon": .7, "tasalli": .6, "fit": .4,
    # Urdu script
    "اچھا": .7, "اچھی": .7, "اچھے": .7, "بہترین": 1, "زبردست": 1, "شاندار": 1, "خوبصورت": .9,
    "مزیدار": 1, "لذیذ": 1, "عمدہ": .9, "کمال": 1, "لاجواب": 1, "صاف": .7, "خوش": .7, "پسند": .7,
    "شکریہ": .5, "آرام": .5, "بااخلاق": .8, "خوشگوار": .8, "مناسب": .5, "سستا": .4, "سکون": .7,
    "تسلی": .6, "پیارا": .8, "پیاری": .8, "مہربان": .7, "منظم": .7,
}
NEG = {
    # English
    "bad": .8, "poor": .8, "terrible": 1, "horrible": 1, "awful": 1, "worst": 1, "dirty": .9, "rude": 1,
    "slow": .6, "late": .6, "delay": .6, "delayed": .6, "crowded": .5, "expensive": .5, "overpriced": .8,
    "disappointed": .9, "disappointing": .9, "letdown": .9, "issue": .5, "issues": .5, "problem": .6,
    "problems": .6, "broken": .8, "noisy": .6, "loud": .4, "struggled": .6, "smaller": .4,
    "short-handed": .6, "unprofessional": .9, "mess": .7, "messy": .7, "waste": .9, "unhygienic": 1,
    "smelly": .9, "smell": .5, "stale": .9, "bland": .7, "tasteless": .9, "chaos": .8,
    "chaotic": .8, "careless": .8, "unhelpful": .8, "uncomfortable": .7, "lacking": .5, "missing": .5,
    "cramped": .6, "faulty": .8, "failed": .7, "cancelled": .4, "worse": .8, "fraud": 1,
    "cheated": 1, "scam": 1, "rip-off": 1, "ripoff": 1, "stuffy": .7, "sweating": .7, "leaking": .7,
    "attention": .2, "hidden": .4, "overcharged": .9, "mismanaged": .9, "mismanagement": .9,
    # Roman Urdu
    "bura": .8, "buri": .8, "bure": .8, "buray": .8, "bekar": .9, "bekaar": .9, "bakwas": 1, "bakwaas": 1,
    "ganda": .9, "gandi": .9, "gande": .9, "gandagi": .9, "kharab": .9, "kharaab": .9, "ghatiya": 1,
    "ghatya": 1, "mehnga": .5, "mehenga": .5, "mehngi": .5, "mehangi": .5, "dhoka": 1, "dhokha": 1,
    "badtameez": 1, "badtameezi": 1, "bedtameez": 1, "deir": .6, "dair": .6, "der": .5, "susti": .6,
    "sust": .6, "pareshan": .7, "pareshani": .7, "masla": .5, "masail": .5, "takleef": .7, "mayoos": .9,
    "mayus": .9, "naraz": .7, "garmi": .6, "zaya": .9, "loot": .9, "shor": .5, "badintezami": 1,
    "badbu": .9, "badboo": .9,
    # Urdu script
    "برا": .8, "بری": .8, "برے": .8, "بیکار": .9, "بکواس": 1, "گندا": .9, "گندی": .9, "گندے": .9,
    "گندگی": .9, "خراب": .9, "گھٹیا": 1, "مہنگا": .5, "مہنگی": .5, "دھوکا": 1, "دھوکہ": 1,
    "بدتمیز": 1, "بدتمیزی": 1, "دیر": .6, "سست": .6, "پریشان": .7, "پریشانی": .7, "مسئلہ": .5,
    "مسائل": .5, "تکلیف": .7, "مایوس": .9, "ناراض": .7, "گرمی": .6, "ضائع": .9, "شور": .5,
    "بدانتظامی": 1, "بدبو": .9, "لوٹ": .8,
}
# Multi-word expressions, matched on the lower-cased clause before tokenising: phrase -> polarity.
PHRASES = {
    "value for money": .8, "worth it": .8, "worth every": .9, "on time": .6, "highly recommended": 1,
    "highly recommend": 1, "will definitely book again": 1, "would book again": .7, "book again": .5,
    "not worth": -.9, "waste of money": -1, "not as promised": -.8, "nothing special": -.1,
    "ups and downs": -.1, "room to improve": -.3, "could have been": -.3, "could be better": -.4,
    "would not book": -.9, "wouldn't book": -.9, "never again": -1, "not recommended": -1,
    "went wrong": -.7, "did not go as planned": -.8, "didn't work": -.7, "did not work": -.7,
    "kicked in instantly": .6, "answered every call": .6, "charged us extra": -.8, "charged extra": -.8, "extra charges": -.7, "hidden charges": -.9, "kept everyone comfortable": .8, "took a minute": -.3, "needed more attention": -.6, "later than promised": -.7,
    "different from the package": -.6, "than the photos suggested": -.5, "had issues": -.6,
    "paisa wasool": .9, "paise wasool": .9, "paise zaya": -1, "paisay zaya": -1, "paise barbad": -1,
    "time pe": .6, "time par": .6, "waqt par": .6, "waqt pe": .6, "bura haal": -.9, "dobara aayenge": .8,
    "dobara ayenge": .8, "dobara nahi": -.9, "kabhi nahi": -.8, "maza aa gaya": 1, "maza agaya": 1,
    "maza nahi": -.8, "dil khush": 1, "khana thanda": -.8, "thanda khana": -.8, "ac band": -.7,
    "light chali": -.6, "bijli chali": -.6, "intezar karna": -.6, "intezaar karna": -.6,
    "وقت پر": .6, "پیسے ضائع": -1, "دوبارہ ضرور": .8, "دوبارہ نہیں": -.9, "مزہ آ گیا": 1, "مزہ آگیا": 1,
    "کھانا ٹھنڈا": -.8, "اے سی بند": -.7, "بجلی چلی": -.6, "دل خوش": 1,
}
NEUTRAL_MARKERS = {"okay", "ok", "fine", "average", "decent", "so-so", "mixed", "alright", "theek", "thik",
                   "ٹھیک", "normal", "standard", "acceptable", "nothing special", "ups and downs", "was fine"}
INTENSIFIERS = {"very": 1.5, "really": 1.4, "so": 1.3, "extremely": 1.7, "super": 1.5, "highly": 1.5,
                "too": 1.4, "absolutely": 1.6, "totally": 1.4, "truly": 1.4, "incredibly": 1.6,
                "bohat": 1.5, "bohot": 1.5, "bahut": 1.5, "bht": 1.5, "boht": 1.5, "bhot": 1.5, "buhat": 1.5,
                "bohut": 1.5, "kafi": 1.3, "kaafi": 1.3, "intehai": 1.7, "zyada": 1.3, "ziada": 1.3,
                "bilkul": 1.4, "behad": 1.6, "sab": 1.0,
                "بہت": 1.5, "کافی": 1.3, "انتہائی": 1.7, "بےحد": 1.6, "بیحد": 1.6, "زیادہ": 1.3, "بالکل": 1.4}
DIMINISHERS = {"bit": .6, "little": .6, "slightly": .6, "somewhat": .7, "thoda": .6, "thora": .6,
               "thori": .6, "thodi": .6, "تھوڑا": .6, "تھوڑی": .6}
NEG_EN = {"not", "no", "never", "hardly", "barely", "without", "nothing", "neither", "nor", "cannot", "cant"}
NEG_UR = {"nahi", "nahin", "nai", "nhi", "nahee", "nahin", "na", "mat", "نہیں", "نہ", "مت"}

ASPECTS = {
    "food": ["food", "dinner", "lunch", "meal", "menu", "biryani", "buffet", "dessert", "catering", "cake",
             "tea", "chai", "bbq", "karahi", "qorma", "korma", "dish", "dishes", "taste", "tasty",
             "delicious", "khana", "khaana", "khane", "khaane", "کھانا", "کھانے", "ذائقہ", "بریانی",
             "mazedar", "lazeez", "مزیدار", "لذیذ"],
    "service": ["service", "staff", "waiter", "waiters", "team", "coordinator", "host", "manager", "crew",
                "polite", "rude", "responsive", "helpful", "behaviour", "behavior", "hospitality",
                "short-handed", "valet", "amla", "amlah", "banday", "log", "tameez", "badtameez", "khidmat",
                "عملہ", "سروس", "ویٹر", "بدتمیز", "رویہ"],
    "decor": ["decor", "decoration", "decorations", "ambience", "ambiance", "lighting", "lights", "stage",
              "theme", "flowers", "atmosphere", "look", "looked", "stunning", "beautiful", "setup",
              "sajawat", "sajaawat", "khoobsurat", "khubsurat", "سجاوٹ", "ماحول", "خوبصورت", "اسٹیج"],
    "value": ["price", "prices", "value", "money", "cost", "costly", "expensive", "cheap", "affordable",
              "overpriced", "package", "budget", "rate", "rates", "charges", "charged", "paisa", "paise",
              "paisay", "qeemat", "mehnga", "mehenga", "mehngi", "sasta", "قیمت", "پیسے", "مہنگا", "سستا"],
    "cleanliness": ["clean", "cleanliness", "dirty", "washroom", "washrooms", "toilet", "toilets", "hygiene",
                    "hygienic", "unhygienic", "spotless", "smell", "smelly", "saaf", "safai", "ganda", "gandi",
                    "gandagi", "صاف", "صفائی", "گندا", "گندی", "گندگی", "واش روم"],
    "parking": ["parking", "car", "cars", "valet", "park", "گاڑی", "پارکنگ"],
    "cooling": ["ac", "a/c", "air conditioning", "air-conditioning", "air conditioner", "cooling", "heat",
                "hot", "fans", "fan", "garmi", "thanda", "thandak", "اے سی", "گرمی", "ٹھنڈا", "ٹھنڈک"],
    "sound_av": ["sound", "music", "speakers", "speaker", "mic", "mics", "microphone", "projector", "av",
                 "audio", "dj", "screen", "wifi", "wi-fi", "loud", "awaz", "awaaz", "آواز", "ساؤنڈ", "میوزک"],
    "location": ["location", "located", "access", "road", "roads", "traffic", "directions", "far", "reach",
                 "nearby", "entrance", "entry", "jagah", "rasta", "raasta", "فاصلہ", "راستہ", "لوکیشن"],
    "management": ["management", "managed", "organised", "organized", "time", "timing", "schedule", "late",
                   "delay", "delayed", "punctual", "later", "on time", "time pe", "time par", "waqt par", "booking", "setup", "arrangement", "arrangements",
                   "intezam", "intizam", "intezaam", "waqt", "dair", "deir", "der", "انتظام", "وقت", "دیر",
                   "بدانتظامی", "منیجمنٹ"],
    "power_backup": ["generator", "load-shedding", "loadshedding", "load shedding", "backup", "electricity",
                     "power", "ups", "bijli", "light", "lights gone", "بجلی", "جنریٹر", "لوڈشیڈنگ"],
}
# Words in an aspect list that are too generic to count on their own for that aspect.
ASPECT_WEAK = {"decor": {"look", "looked", "beautiful", "stunning", "setup"}, "power_backup": {"light", "power"},
               "location": {"jagah", "entry"}, "management": {"booking", "time", "setup"}, "service": {"log", "team"},
               "food": {"tea", "dinner", "lunch", "taste"}, "value": {"package", "rate"}, "cooling": {"hot", "fan"}}

ROMAN_MARKERS = {
    "hai", "hain", "hy", "tha", "thi", "thay", "thy", "hua", "hui", "huay", "howa", "hoa", "bohat", "bohot",
    "bahut", "boht", "bhot", "bht", "buhat", "acha", "achha", "achi", "achhi", "achay", "achhe", "nahi",
    "nahin", "nhi", "aur", "ka", "ki", "ke", "ko", "se", "mein", "mai", "bhi", "sab", "kuch", "wala", "wali",
    "walay", "jagah", "khana", "khaana", "bilkul", "zabardast", "kharab", "yeh", "ye", "woh", "wo", "hum",
    "humne", "hamari", "hamara", "hamare", "apna", "mera", "meri", "gaya", "gayi", "gaye", "kiya", "kia",
    "diya", "raha", "rahi", "rahe", "laga", "lagi", "kafi", "kaafi", "thoda", "thora", "zyada", "ziada",
    "bura", "buri", "ganda", "gandi", "umda", "mazedar", "theek", "thik", "shukriya", "sirf", "phir",
    "lekin", "magar", "waqt", "dair", "paise", "paisa", "logon", "mehman", "mehmaan", "shadi", "shaadi",
    "intezam", "intizam", "saaf", "garmi", "thanda", "garam", "bekar", "bakwas", "behtareen", "shandaar",
    "kamaal", "lazeez", "lajawab", "pasand", "khush", "agaya", "aa", "gya", "dobara", "zaroor", "zaror",
    "karna", "karne", "karte", "kar", "liye", "lye", "wahan", "yahan", "abhi", "pe", "par", "tou", "toh",
}

SPLIT_SENT = re.compile(r"(?<=[.!?۔؟])\s+|\n+|(?<=[.!?۔؟])(?=[^\s.!?])")
SPLIT_CLAUSE = re.compile(r"\s*[;]\s*|\s*,?\s+\b(?:but|however|although|though|whereas|lekin|magar|phir bhi)\b\s+"
                          r"|\s*،?\s*(?:لیکن|مگر|البتہ)\s+", re.IGNORECASE)
TOKEN = re.compile(r"[a-z0-9]+(?:[-'/][a-z0-9]+)*|[؀-ۿݐ-ݿ]+", re.IGNORECASE)
URDU_CHAR = re.compile(r"[؀-ۿݐ-ݿ]")
LATIN_CHAR = re.compile(r"[A-Za-z]")
URL = re.compile(r"(https?://|www\.|\b[a-z0-9-]+\.(?:com|pk|net|org|io|info|biz|xyz|me)\b)", re.IGNORECASE)
PHONE = re.compile(r"(?:\+?92[\s-]?|0)3\d{2}[\s-]?\d{7}\b|\b\d{4}[\s-]?\d{7}\b|\b\d{11,13}\b")
EMAIL = re.compile(r"\b[\w.+-]+@[\w-]+\.[\w.]+\b")
SPAM_WORDS = re.compile(r"\b(?:click here|free money|earn \d+|whatsapp me|contact me|dm me|discount code|promo code|"
                        r"visit my|follow me|subscribe|casino|crypto|loan offer)\b", re.IGNORECASE)


def clamp(x, lo=-1.0, hi=1.0):
    return max(lo, min(hi, x))


def detect_language(text):
    urdu = len(URDU_CHAR.findall(text))
    latin = len(LATIN_CHAR.findall(text))
    if urdu + latin == 0:
        return "en"
    if urdu / (urdu + latin) >= 0.3:
        return "ur"
    toks = [t.lower() for t in TOKEN.findall(text)]
    if not toks:
        return "en"
    hits = sum(1 for t in toks if t in ROMAN_MARKERS)
    if hits >= 2 and hits / len(toks) >= 0.15 or (hits >= 1 and len(toks) <= 4 and hits / len(toks) >= 0.25):
        return "roman_ur"
    return "en"


def split_clauses(text):
    out = []
    for sent in SPLIT_SENT.split(text or ""):
        sent = sent.strip()
        if not sent:
            continue
        for c in SPLIT_CLAUSE.split(sent):
            c = (c or "").strip(" ,.-")
            if len(c) >= 2:
                out.append(c)
    return out


def lexicon_score(clause, lang):
    """Returns (score -1..1, hits, neutral_marker, evidence_weight)."""
    low = clause.lower()
    total = 0.0
    hits = 0
    for ph, pol in PHRASES.items():
        if ph in low:
            # A negated phrase ("not on time", "time pe nahi") flips.
            idx = low.find(ph)
            before = low[max(0, idx - 12):idx]
            after = low[idx + len(ph):idx + len(ph) + 10]
            neg = bool(re.search(r"\b(not|never|no)\s*$", before)) or (lang != "en" and re.search(r"^\s*(nahi|nahin|nhi|نہیں)", after))
            total += -pol if neg else pol
            hits += 1
            low = low.replace(ph, " ")
    toks = TOKEN.findall(low)
    neutral = any(m in clause.lower() for m in NEUTRAL_MARKERS if " " in m) or any(t in NEUTRAL_MARKERS for t in toks)
    has_food = any(t in ("food", "khana", "khaana", "کھانا", "dinner", "biryani", "meal") for t in toks)
    has_cool = any(t in ("ac", "hall", "room", "cooling") for t in toks)
    for i, t in enumerate(toks):
        pol = 0.0
        if t in POS:
            pol = POS[t]
        elif t in NEG:
            pol = -NEG[t]
        elif t in ("thanda", "cold", "ٹھنڈا"):
            pol = -.8 if has_food else (.6 if has_cool else 0)
        elif t in ("garam", "warm", "گرم"):
            pol = .6 if has_food else (-.6 if has_cool else 0)
        elif t == "hot":
            pol = .5 if has_food else (-.5 if has_cool else 0)
        if pol == 0:
            continue
        mult = 1.0
        for j in range(max(0, i - 2), i):
            w = toks[j]
            if w in INTENSIFIERS:
                mult *= INTENSIFIERS[w]
            if w in DIMINISHERS:
                mult *= DIMINISHERS[w]
        negated = False
        if lang == "en":
            window = toks[max(0, i - 3):i]
            negated = any(w in NEG_EN or w.endswith("n't") or w in ("wasnt", "didnt", "isnt", "dont", "werent") for w in window)
        else:
            after = toks[i + 1:i + 3]
            before = toks[max(0, i - 2):i]
            negated = any(w in NEG_UR for w in after) or any(w in NEG_UR or w in NEG_EN for w in before)
        if negated:
            pol = -pol * (0.8 if pol > 0 else 0.6)  # "not bad" is weaker than "good"
        total += pol * mult
        hits += 1
    if hits == 0:
        return 0.0, 0, neutral, 0.0
    score = math.tanh(total * 1.1)
    if neutral and abs(total) < 1.2:
        score *= 0.4
    return score, hits, neutral, min(1.0, 0.45 + 0.2 * hits)


class Model:
    def __init__(self, model_dir):
        import numpy as np
        import onnxruntime as ort
        from tokenizers import Tokenizer
        try:
            ort.disable_telemetry_events()
        except Exception:
            pass
        self.np = np
        so = ort.SessionOptions()
        so.intra_op_num_threads = 1
        so.inter_op_num_threads = 1
        so.log_severity_level = 3
        self.sess = ort.InferenceSession(os.path.join(model_dir, "model_quantized.onnx"), so,
                                         providers=["CPUExecutionProvider"])
        self.inputs = {i.name for i in self.sess.get_inputs()}
        self.tok = Tokenizer.from_file(os.path.join(model_dir, "tokenizer.json"))
        self.tok.enable_truncation(128)
        with open(os.path.join(model_dir, "config.json")) as f:
            labels = json.load(f)["id2label"]
        self.pos_idx = next(int(k) for k, v in labels.items() if v.lower().startswith("pos"))
        self.neg_idx = next(int(k) for k, v in labels.items() if v.lower().startswith("neg"))

    def scores(self, texts):
        """Returns list of p(pos) - p(neg) in -1..1."""
        np = self.np
        out = [0.0] * len(texts)
        order = sorted(range(len(texts)), key=lambda i: len(texts[i]))
        for b in range(0, len(order), BATCH):
            idx = order[b:b + BATCH]
            encs = self.tok.encode_batch([texts[i] for i in idx])
            width = max(len(e.ids) for e in encs)
            ids = np.zeros((len(encs), width), dtype=np.int64)
            mask = np.zeros((len(encs), width), dtype=np.int64)
            for r, e in enumerate(encs):
                ids[r, :len(e.ids)] = e.ids
                mask[r, :len(e.ids)] = 1
            feed = {"input_ids": ids, "attention_mask": mask}
            if "token_type_ids" in self.inputs:
                feed["token_type_ids"] = np.zeros_like(ids)
            logits = self.sess.run(None, {k: v for k, v in feed.items() if k in self.inputs})[0]
            logits = logits - logits.max(axis=1, keepdims=True)
            p = np.exp(logits)
            p /= p.sum(axis=1, keepdims=True)
            for r, i in enumerate(idx):
                out[i] = float(p[r, self.pos_idx] - p[r, self.neg_idx])
        return out


def score_clauses(clauses, model):
    """clauses: list of text. Returns list of dicts {text, lang, score, conf}."""
    info = []
    need_model = []
    for c in clauses:
        lang = detect_language(c)
        lex, hits, neutral, ev = lexicon_score(c, lang)
        info.append({"text": c, "lang": lang, "lex": lex, "hits": hits, "neutral": neutral, "ev": ev, "model": None})
        if model is not None and lang in ("en", "roman_ur"):
            need_model.append(len(info) - 1)
    if need_model:
        ms = model.scores([info[i]["text"] for i in need_model])
        for i, m in zip(need_model, ms):
            info[i]["model"] = m
    for d in info:
        m, lex, hits = d["model"], d["lex"], d["hits"]
        if d["lang"] == "en" and m is not None:
            if hits == 0:
                # No sentiment words: factual sentences make SST-2 overconfident, so damp it.
                s = 0.55 * m
                conf = 0.5 * abs(m)
            elif (m > 0) != (lex > 0) and abs(lex) >= 0.5:
                s = 0.3 * m + 0.7 * lex
                conf = 0.45
            else:
                s = 0.6 * m + 0.4 * lex
                conf = min(1.0, 0.5 * abs(m) + 0.5 * d["ev"])
            if d["neutral"] and abs(lex) < 0.5:
                s *= 0.35
        elif d["lang"] == "roman_ur":
            # The English model reads Roman Urdu as noise; only use it (weakly) when no lexicon word matched.
            if hits:
                s = lex
                conf = d["ev"]
            else:
                s = 0.25 * (m or 0.0)
                conf = 0.2
        else:  # Urdu script
            s = lex
            conf = d["ev"] if hits else 0.1
        d["score"] = clamp(s)
        d["conf"] = round(clamp(conf, 0, 1), 3)
    return info


def aspects_of(text):
    low = " " + text.lower() + " "
    toks = set(TOKEN.findall(low))
    found = []
    for name, words in ASPECTS.items():
        strong = False
        weak = 0
        for w in words:
            hit = (w in toks) if (" " not in w and "/" not in w) else (w in low)
            if not hit:
                continue
            if w in ASPECT_WEAK.get(name, ()):
                weak += 1
            else:
                strong = True
                break
        if strong or weak >= 2:
            found.append(name)
    return found


def label_of(s):
    return "positive" if s > 0.25 else ("negative" if s < -0.25 else "neutral")


def flags_for(review, text, sentiment, confidence):
    flags = []
    comment = (review.get("comment") or "").strip()
    words = TOKEN.findall(comment)
    if len(comment) < 15 or len(words) < 3:
        flags.append("very_short")
    if URL.search(text) or PHONE.search(text) or EMAIL.search(text) or SPAM_WORDS.search(text) \
            or re.search(r"(.)\1{6,}", text):
        flags.append("spam")
    letters = re.findall(r"[A-Za-z]", comment)
    if len(letters) >= 12 and sum(1 for ch in letters if ch.isupper()) / len(letters) > 0.7:
        flags.append("all_caps")
    try:
        rating = int(review.get("rating") or 0)
    except (TypeError, ValueError):
        rating = 0
    if "very_short" not in flags and confidence >= 0.35:
        if rating >= 4 and sentiment <= -0.35 or rating and rating <= 2 and sentiment >= 0.35:
            flags.append("rating_text_mismatch")
    return flags


def analyze_reviews(reviews, model):
    # Score every clause of every review in one model pass.
    all_clauses = []
    spans = []
    for r in reviews:
        title = (r.get("title") or "").strip()
        comment = (r.get("comment") or "").strip()
        start = len(all_clauses)
        tcl = split_clauses(title) if title else []
        ccl = split_clauses(comment)
        all_clauses.extend(tcl + ccl)
        spans.append((start, len(tcl), len(ccl)))
    scored = score_clauses(all_clauses, model) if all_clauses else []

    results = []
    for r, (start, nt, nc) in zip(reviews, spans):
        parts = scored[start:start + nt + nc]
        weighted = 0.0
        wsum = 0.0
        csum = 0.0
        langs = {}
        aspects = {}
        pos_ph, neg_ph = [], []
        for k, d in enumerate(parts):
            is_title = k < nt
            # Title counts half; clauses with real evidence count more than flat ones.
            w = (0.5 if is_title else 1.0) * (0.4 + d["conf"])
            weighted += w * d["score"]
            wsum += w
            csum += w * d["conf"]
            nwords = len(TOKEN.findall(d["text"]))
            langs[d["lang"]] = langs.get(d["lang"], 0) + nwords
            if is_title:
                continue
            for a in aspects_of(d["text"]):
                agg = aspects.setdefault(a, [0.0, 0])
                agg[0] += d["score"]
                agg[1] += 1
            # Highlights/concerns: short clauses that talk about a concrete aspect.
            if 2 <= nwords <= 14 and len(d["text"]) <= 90 and aspects_of(d["text"]):
                if d["score"] >= 0.5 and d["conf"] >= 0.4:
                    pos_ph.append((d["score"], d["text"]))
                elif d["score"] <= -0.35:
                    neg_ph.append((-d["score"], d["text"]))
        sentiment = weighted / wsum if wsum else 0.0
        confidence = csum / wsum if wsum else 0.0
        language = max(langs, key=langs.get) if langs else "en"
        if len(langs) > 1 and sorted(langs.values())[-2] >= 0.3 * sum(langs.values()):
            language = "mixed"
        text = ((r.get("title") or "") + " " + (r.get("comment") or "")).strip()
        results.append({
            "id": r.get("id"),
            "sentiment": round(clamp(sentiment), 4),
            "label": label_of(sentiment),
            "confidence": round(clamp(confidence, 0, 1), 3),
            "language": language,
            "aspects": {a: {"sentiment": round(s / n, 4), "mentions": n} for a, (s, n) in aspects.items()},
            "flags": flags_for(r, text, sentiment, confidence),
            "phrases": {
                "positive": [t for _, t in sorted(pos_ph, reverse=True)[:3]],
                "negative": [t for _, t in sorted(neg_ph, reverse=True)[:3]],
            },
            "model_version": MODEL_VERSION if model is not None else LEXICON_VERSION,
        })
    return results


def load_model():
    try:
        return Model(MODEL_DIR), None
    except Exception as e:  # missing model or runtime: fall back to the lexicon only
        return None, f"{type(e).__name__}: {e}"


def run_eval():
    """Accuracy per split and language: the hybrid vs. the model alone (3 classes, thresholds +-0.25)."""
    with open(os.path.join(HERE, "eval_set.json")) as f:
        data = json.load(f)
    model, err = load_model()
    if err:
        print(json.dumps({"warning": err}), file=sys.stderr)
    t0 = time.time()
    res = analyze_reviews([{"id": i, "title": "", "comment": d["text"], "rating": None} for i, d in enumerate(data)], model)
    ms = (time.time() - t0) * 1000 / max(1, len(data))
    base = model.scores([d["text"] for d in data]) if model else [0.0] * len(data)
    table = {}
    wrong = []
    for d, r, b in zip(data, res, base):
        for key in ((d.get("split", "dev"), d["lang"]), ("all", d["lang"]), ("all", "all")):
            g = table.setdefault(key, [0, 0, 0])
            g[0] += r["label"] == d["label"]
            g[1] += label_of(b) == d["label"]
            g[2] += 1
        if r["label"] != d["label"]:
            wrong.append(f'  [{d.get("split", "dev")}/{d["lang"]}] want {d["label"]:8} got {r["label"]:8} ({r["sentiment"]:+.2f}) {d["text"]}')
    print(f"model: {MODEL_VERSION if model else LEXICON_VERSION + ' (lexicon only)'}   ({ms:.1f} ms/sentence incl. model load)")
    print(f"{'split':8} {'language':9} {'hybrid':>14} {'model only':>14}")
    for split in ("dev", "holdout", "all"):
        for lang in ("en", "roman_ur", "ur", "all"):
            if (split, lang) in table:
                ok, okb, n = table[(split, lang)]
                print(f"{split:8} {lang:9} {ok:3}/{n:<3} {100 * ok / n:5.1f}%  {okb:3}/{n:<3} {100 * okb / n:5.1f}%")
    if "-v" in sys.argv and wrong:
        print("misclassified:\n" + "\n".join(wrong))


def main():
    if "--eval" in sys.argv:
        run_eval()
        return
    if "--status" in sys.argv:
        model, err = load_model()
        print(json.dumps({"ok": model is not None, "model_version": MODEL_VERSION if model else LEXICON_VERSION,
                          "model_dir": MODEL_DIR, "error": err}))
        return
    raw = sys.stdin.read()
    reviews = json.loads(raw) if raw.strip() else []
    if not isinstance(reviews, list):
        raise SystemExit("expected a JSON list")
    model, err = load_model()
    if err:
        print(f"warning: model unavailable, lexicon only ({err})", file=sys.stderr)
    json.dump(analyze_reviews(reviews, model), sys.stdout, ensure_ascii=False)


if __name__ == "__main__":
    main()
