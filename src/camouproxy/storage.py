import sqlite3
import os
from typing import List, Dict, Any, Optional

DEFAULT_DB_PATH = os.path.expanduser("~/.camouproxy_history.db")

def get_db_path(db_path: Optional[str] = None) -> str:
    return db_path if db_path is not None else DEFAULT_DB_PATH

def init_db(db_path: Optional[str] = None) -> None:
    target_path = get_db_path(db_path)
    # Ensure directory exists if db_path has a parent directory
    parent_dir = os.path.dirname(target_path)
    if parent_dir and not os.path.exists(parent_dir):
        os.makedirs(parent_dir, exist_ok=True)
        
    conn = sqlite3.connect(target_path)
    cursor = conn.cursor()
    cursor.execute("""
        CREATE TABLE IF NOT EXISTS proxy_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            raw_proxy TEXT UNIQUE,
            ip TEXT,
            country TEXT,
            country_code TEXT,
            isp TEXT,
            last_used TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    """)
    conn.commit()
    conn.close()

def save_proxy_record(record: Dict[str, Any], db_path: Optional[str] = None) -> None:
    target_path = get_db_path(db_path)
    init_db(target_path)
    
    raw_proxy = record.get("raw_proxy") or record.get("raw")
    if not raw_proxy:
        raise ValueError("Record must contain 'raw_proxy' or 'raw' field")
        
    conn = sqlite3.connect(target_path)
    cursor = conn.cursor()
    cursor.execute("""
        INSERT INTO proxy_history (raw_proxy, ip, country, country_code, isp, last_used)
        VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT(raw_proxy) DO UPDATE SET
            ip=excluded.ip,
            country=excluded.country,
            country_code=excluded.country_code,
            isp=excluded.isp,
            last_used=CURRENT_TIMESTAMP
    """, (
        raw_proxy,
        record.get("ip"),
        record.get("country"),
        record.get("country_code"),
        record.get("isp")
    ))
    conn.commit()
    conn.close()

def get_all_proxies(db_path: Optional[str] = None) -> List[Dict[str, Any]]:
    target_path = get_db_path(db_path)
    init_db(target_path)
    
    conn = sqlite3.connect(target_path)
    conn.row_factory = sqlite3.Row
    cursor = conn.cursor()
    cursor.execute("SELECT * FROM proxy_history ORDER BY last_used DESC")
    rows = [dict(row) for row in cursor.fetchall()]
    conn.close()
    return rows

def delete_proxy_record(proxy_id: int, db_path: Optional[str] = None) -> bool:
    target_path = get_db_path(db_path)
    init_db(target_path)
    
    conn = sqlite3.connect(target_path)
    cursor = conn.cursor()
    cursor.execute("DELETE FROM proxy_history WHERE id = ?", (proxy_id,))
    deleted = cursor.rowcount > 0
    conn.commit()
    conn.close()
    return deleted
