"""Get translations from the database and render via V8."""
import sqlite3
import json
import os
import sys

DB_PATH = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "database", "database.sqlite")
SCRIPTS_DIR = os.path.dirname(os.path.abspath(__file__))

sys.path.insert(0, SCRIPTS_DIR)

def get_tables():
    db = sqlite3.connect(DB_PATH)
    tables = [t[0] for t in db.execute("SELECT name FROM sqlite_master WHERE type='table'").fetchall()]
    db.close()
    return tables

def get_translations(book_id=2, language="af"):
    db = sqlite3.connect(DB_PATH)
    db.row_factory = sqlite3.Row
    
    # Check for translations table
    tables = get_tables()
    print(f"Tables: {tables}")
    
    # Look for translation-related tables
    for t in tables:
        if "translat" in t.lower() or "page" in t.lower():
            cols = db.execute(f"PRAGMA table_info({t})").fetchall()
            print(f"\n{t}: {[c[1] for c in cols]}")
            count = db.execute(f"SELECT COUNT(*) FROM {t}").fetchone()[0]
            print(f"  Rows: {count}")
            if count > 0 and count < 50:
                rows = db.execute(f"SELECT * FROM {t} LIMIT 3").fetchall()
                for r in rows:
                    print(f"  Sample: {dict(r)}")
    
    db.close()

if __name__ == "__main__":
    get_translations()
