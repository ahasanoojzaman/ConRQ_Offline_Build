import sqlite3
import os
from datetime import date

DB_NAME = "trending.db"

def init_db():
    conn = sqlite3.connect(DB_NAME)
    cursor = conn.cursor()
    cursor.execute('''
        CREATE TABLE IF NOT EXISTS trending_repos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            scrape_date DATE NOT NULL,
            repo_name TEXT NOT NULL,
            stars INTEGER,
            language TEXT
        )
    ''')
    conn.commit()
    conn.close()
    print(f"Database initialized at {DB_NAME}")

def insert_repos(repos):
    conn = sqlite3.connect(DB_NAME)
    cursor = conn.cursor()
    today = date.today().isoformat()
    
    for repo in repos:
        cursor.execute('''
            INSERT INTO trending_repos (scrape_date, repo_name, stars, language)
            VALUES (?, ?, ?, ?)
        ''', (today, repo['name'], repo['stars'], repo['language']))
        
    conn.commit()
    conn.close()
    print(f"Inserted {len(repos)} repositories for {today}")

if __name__ == "__main__":
    init_db()
