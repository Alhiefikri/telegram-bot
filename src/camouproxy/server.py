from fastapi import FastAPI, HTTPException, Query
from pydantic import BaseModel
from typing import Optional, Dict, Any, List
import os
import threading

from src.camouproxy.proxy_parser import parse_proxy_string
from src.camouproxy.ip_detector import detect_ip_metadata
from src.camouproxy.browser_launcher import launch_camoufox_session
from src.camouproxy.netflix_loop import run_netflix_trial_loop
from src.camouproxy.storage import save_proxy_record, get_all_proxies, delete_proxy_record

app = FastAPI(title="CamouProxy Launcher")


class ProxyTestRequest(BaseModel):
    proxy: str


class LaunchRequest(BaseModel):
    proxy: str
    block_media: bool = False
    mode: str = "normal"


def _get_db_path() -> Optional[str]:
    return getattr(app.state, "db_path", os.environ.get("CAMOUPROXY_DB_PATH"))


@app.post("/api/test-proxy")
async def api_test_proxy(req: ProxyTestRequest) -> Dict[str, Any]:
    try:
        config = parse_proxy_string(req.proxy)
        meta = await detect_ip_metadata(config)
        save_proxy_record({"raw_proxy": req.proxy, **meta}, db_path=_get_db_path())
        return {"status": "success", "config": config, "metadata": meta}
    except Exception as e:
        raise HTTPException(status_code=400, detail=str(e))


@app.post("/api/launch")
async def api_launch_browser(req: LaunchRequest, mode: Optional[str] = Query(None)) -> Dict[str, Any]:
    try:
        config = parse_proxy_string(req.proxy)
        meta = await detect_ip_metadata(config)
        save_proxy_record({"raw_proxy": req.proxy, **meta}, db_path=_get_db_path())

        effective_mode = mode if mode is not None else req.mode

        if effective_mode == "netflix":
            t = threading.Thread(target=run_netflix_trial_loop, args=(config, meta))
        else:
            t = threading.Thread(target=launch_camoufox_session, args=(config, meta, req.block_media))

        t.daemon = True
        t.start()
        return {
            "status": "success",
            "message": f"Browser launched in {effective_mode} mode",
            "metadata": meta
        }
    except Exception as e:
        raise HTTPException(status_code=400, detail=str(e))


@app.get("/api/history")
def api_get_history() -> List[Dict[str, Any]]:
    return get_all_proxies(db_path=_get_db_path())


@app.delete("/api/history/{proxy_id}")
def api_delete_history(proxy_id: int) -> Dict[str, Any]:
    deleted = delete_proxy_record(proxy_id, db_path=_get_db_path())
    if not deleted:
        raise HTTPException(status_code=404, detail="Proxy record not found")
    return {"status": "success", "message": f"Deleted proxy record {proxy_id}"}
