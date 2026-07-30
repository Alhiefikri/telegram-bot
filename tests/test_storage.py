import pytest
import os
import sqlite3
from src.camouproxy.storage import (
    init_db,
    save_proxy_record,
    get_all_proxies,
    delete_proxy_record
)

@pytest.fixture
def temp_db(tmp_path):
    db_file = tmp_path / "test_history.db"
    return str(db_file)

def test_init_db(temp_db):
    assert not os.path.exists(temp_db)
    init_db(temp_db)
    assert os.path.exists(temp_db)
    
    conn = sqlite3.connect(temp_db)
    cursor = conn.cursor()
    cursor.execute("SELECT name FROM sqlite_master WHERE type='table' AND name='proxy_history'")
    assert cursor.fetchone() is not None
    conn.close()

def test_save_and_get_all_proxies(temp_db):
    record = {
        "raw_proxy": "http://user:pass@1.2.3.4:8080",
        "ip": "1.2.3.4",
        "country": "India",
        "country_code": "IN",
        "isp": "Airtel"
    }
    save_proxy_record(record, db_path=temp_db)
    
    proxies = get_all_proxies(db_path=temp_db)
    assert len(proxies) == 1
    assert proxies[0]["raw_proxy"] == record["raw_proxy"]
    assert proxies[0]["ip"] == "1.2.3.4"
    assert proxies[0]["country"] == "India"
    assert proxies[0]["country_code"] == "IN"
    assert proxies[0]["isp"] == "Airtel"
    assert "id" in proxies[0]
    assert "last_used" in proxies[0]

def test_upsert_proxy_record(temp_db):
    initial_record = {
        "raw_proxy": "http://user:pass@1.2.3.4:8080",
        "ip": "1.2.3.4",
        "country": "India",
        "country_code": "IN",
        "isp": "Airtel"
    }
    save_proxy_record(initial_record, db_path=temp_db)
    
    updated_record = {
        "raw_proxy": "http://user:pass@1.2.3.4:8080",
        "ip": "103.1.2.3",
        "country": "United States",
        "country_code": "US",
        "isp": "Cloudflare"
    }
    save_proxy_record(updated_record, db_path=temp_db)
    
    proxies = get_all_proxies(db_path=temp_db)
    assert len(proxies) == 1
    assert proxies[0]["raw_proxy"] == "http://user:pass@1.2.3.4:8080"
    assert proxies[0]["ip"] == "103.1.2.3"
    assert proxies[0]["country"] == "United States"
    assert proxies[0]["country_code"] == "US"
    assert proxies[0]["isp"] == "Cloudflare"

def test_delete_proxy_record(temp_db):
    record1 = {
        "raw_proxy": "http://proxy1.com:8080",
        "ip": "1.1.1.1"
    }
    record2 = {
        "raw_proxy": "http://proxy2.com:8080",
        "ip": "2.2.2.2"
    }
    save_proxy_record(record1, db_path=temp_db)
    save_proxy_record(record2, db_path=temp_db)
    
    proxies = get_all_proxies(db_path=temp_db)
    assert len(proxies) == 2
    
    target_id = proxies[0]["id"]
    deleted = delete_proxy_record(target_id, db_path=temp_db)
    assert deleted is True
    
    remaining = get_all_proxies(db_path=temp_db)
    assert len(remaining) == 1
    assert remaining[0]["id"] != target_id

    # Deleting non-existent ID
    deleted_again = delete_proxy_record(99999, db_path=temp_db)
    assert deleted_again is False

def test_save_proxy_missing_raw(temp_db):
    with pytest.raises(ValueError, match="must contain 'raw_proxy' or 'raw'"):
        save_proxy_record({"ip": "1.2.3.4"}, db_path=temp_db)
