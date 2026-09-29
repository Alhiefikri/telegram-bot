"""Idle proxy for the local Telegram bot stack.

Telegram webhook traffic reaches ngrok, then this proxy (18081), then the
Docker web port (18082). The proxy starts the stack on the first request and
stops it again after a period without traffic, so a laptop only pays for the
backend while the bot is actually being used.
"""

import asyncio
import logging
import os
import time

# Configuration
LISTEN_HOST = "127.0.0.1"
LISTEN_PORT = 18081
BACKEND_HOST = "127.0.0.1"
BACKEND_PORT = 18082
IDLE_TIMEOUT_SEC = 30 * 60  # 30 minutes
START_TIMEOUT_SEC = 120
PROJECT_DIR = "/home/alhiefikri/telegram-bot"
PROJECT_NAME = os.environ.get("COMPOSE_PROJECT_NAME", "telegram-bot-testing")

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler(os.path.join(PROJECT_DIR, "idle-proxy.log")),
        logging.StreamHandler()
    ]
)

last_request_time = time.time()
is_backend_running = False
backend_lock = asyncio.Lock()


async def backend_port_open() -> bool:
    try:
        reader, writer = await asyncio.open_connection(BACKEND_HOST, BACKEND_PORT)
    except Exception:
        return False
    writer.close()
    await writer.wait_closed()
    return True


async def compose(command: str) -> None:
    process = await asyncio.create_subprocess_exec(
        "docker", "compose", "-p", PROJECT_NAME, command,
        cwd=PROJECT_DIR,
        stdout=asyncio.subprocess.DEVNULL,
        stderr=asyncio.subprocess.PIPE,
    )
    _, stderr = await process.communicate()
    if process.returncode != 0:
        logging.warning(
            "docker compose %s gagal (exit %s): %s",
            command,
            process.returncode,
            stderr.decode(errors="replace").strip(),
        )


async def start_backend() -> None:
    global is_backend_running
    async with backend_lock:
        if await backend_port_open():
            is_backend_running = True
            return

        logging.info("Memulai backend (docker compose start)...")
        await compose("start")

        logging.info(f"Menunggu Nginx siap di port {BACKEND_PORT}...")
        deadline = time.time() + START_TIMEOUT_SEC
        while time.time() < deadline:
            if await backend_port_open():
                logging.info("Backend sudah siap menerima traffic!")
                is_backend_running = True
                return
            await asyncio.sleep(1)

        logging.error(f"Backend tidak siap setelah {START_TIMEOUT_SEC} detik.")


async def stop_backend() -> None:
    global is_backend_running
    async with backend_lock:
        if not is_backend_running:
            return
        logging.info("Menghentikan backend (docker compose stop) karena tidak ada aktivitas...")
        await compose("stop")
        is_backend_running = False


async def open_backend():
    await start_backend()

    try:
        return await asyncio.open_connection(BACKEND_HOST, BACKEND_PORT)
    except Exception as e:
        logging.warning(f"Koneksi pertama gagal ({e}), backend mungkin mati. Mencoba menghidupkan lagi...")
        global is_backend_running
        is_backend_running = False

    await start_backend()

    try:
        return await asyncio.open_connection(BACKEND_HOST, BACKEND_PORT)
    except Exception as e:
        logging.error(f"Gagal total konek ke backend: {e}")
        return None


async def handle_client(reader, writer):
    global last_request_time
    last_request_time = time.time()

    backend = await open_backend()
    if backend is None:
        writer.close()
        return
    backend_reader, backend_writer = backend

    async def pipe(r, w):
        try:
            while True:
                data = await r.read(4096)
                if not data:
                    break
                w.write(data)
                await w.drain()
        except Exception:
            pass
        finally:
            w.close()

    await asyncio.gather(pipe(reader, backend_writer), pipe(backend_reader, writer))


async def idle_checker():
    while True:
        await asyncio.sleep(60)
        if is_backend_running and (time.time() - last_request_time > IDLE_TIMEOUT_SEC):
            await stop_backend()


async def main():
    # Make sure backend is in expected state on startup
    # We don't automatically stop on startup to allow quick restarts,
    # but we assume it's running if the web port answers.
    global is_backend_running
    if await backend_port_open():
        is_backend_running = True
        logging.info("Backend sudah berjalan saat proxy dimulai.")
    else:
        is_backend_running = False
        logging.info("Backend saat ini dalam keadaan mati/stop.")

    server = await asyncio.start_server(handle_client, LISTEN_HOST, LISTEN_PORT)
    logging.info(f"Proxy berjalan. Mendengarkan webhook di {LISTEN_HOST}:{LISTEN_PORT}")

    asyncio.create_task(idle_checker())

    async with server:
        await server.serve_forever()


if __name__ == "__main__":
    asyncio.run(main())
