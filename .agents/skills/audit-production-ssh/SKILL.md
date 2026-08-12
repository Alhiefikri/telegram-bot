---
name: audit-production-ssh
description: Read-only production-server auditing over SSH for the configured host `ssh -p 2468 alhiefikri@43.134.177.28`. Use only when the user explicitly asks to audit, inspect, health-check, or diagnose production through SSH, including CPU, memory, disk, processes, listening ports, services, deployments, or recent logs. Trigger on requests such as “audit production”, “cek kesehatan server production”, or “cek server via SSH”; do not use for deploys, restarts, fixes, or other mutations.
---

# Audit Production via SSH

Perform a bounded, evidence-first audit of the configured production host. The default action is observation only; never change production state as part of this skill.

## Activation boundary

- Require an explicit audit or diagnostic request. Do not connect because a user merely mentions production.
- Use exactly `ssh -p 2468 alhiefikri@43.134.177.28`; do not silently substitute another host, user, or port.
- For a different target, stop using this skill and ask for the target-specific procedure.
- Do not put passwords, passphrases, private keys, or command output containing secrets in prompts, files, or reports.

## Connection safety

1. Check that the local `ssh` client exists.
2. Connect with the default SSH host-key verification. Never use `-o StrictHostKeyChecking=no` or discard `known_hosts`.
3. If the host key is new or changed, stop and request fingerprint verification before proceeding.
4. Preserve an interactive PTY when authentication needs a password, passphrase, or MFA. Never bypass an authentication prompt.
5. Run one read-only check per SSH invocation where practical. Record the remote exit status and timestamp; a failed check must not hide later checks.
6. Do not use `sudo` by default. If a check requires privilege, report it as unverified. Use a narrowly scoped read-only `sudo -n` command only after the user explicitly authorizes it.

Connection templates:

```bash
ssh -p 2468 alhiefikri@43.134.177.28 'hostname'
```

Project connection template:

```bash
ssh -p 2468 alhiefikri@43.134.177.28 'cd "$HOME/github/telegram-bot" && pwd && git rev-parse --show-toplevel'
```

## Audit sequence

Run the checks in the order below. Establish identity first, then enter `$HOME/github/telegram-bot` immediately after confirmation. Run every application- or repository-specific check from that directory, adapting only to facts discovered on the host:

### 1. Establish identity and time

```bash
hostname
date -Is
uptime
id
cat /etc/os-release
```

Confirm that the host and account match the intended production target before interpreting results.

### 2. Enter and verify the project

```bash
cd "$HOME/github/telegram-bot" && pwd && git rev-parse --show-toplevel && git status --short --branch && git log -1 --format='%h %ad %s' --date=iso-strict
```

The expected project directory is `$HOME/github/telegram-bot`. If it does not exist or is not the intended repository, report the project-context failure and stop project-specific checks. Never clone, pull, fetch, reset, checkout, or otherwise alter the repository during an audit.

Prefix project-specific remote commands with `cd "$HOME/github/telegram-bot" &&`; host-wide checks may run without that prefix.

### 3. Check capacity and pressure

```bash
free -h
df -hT
df -ih
ps -eo pid,ppid,user,stat,%cpu,%mem,etime,cmd --sort=-%cpu | sed -n '1,25p'
ps -eo pid,ppid,user,stat,%cpu,%mem,etime,cmd --sort=-%mem | sed -n '1,25p'
```

Flag exhausted or nearly exhausted filesystems/inodes, swap pressure, sustained high CPU, runaway memory use, and long-lived processes in abnormal states. Treat process arguments as potentially secret-bearing.

### 4. Check services and exposure

```bash
systemctl --failed --no-legend --no-pager
systemctl list-units --type=service --state=running --no-pager
ss -lntup
```

If systemd is unavailable, state that clearly and use the service manager actually present. Identify the application service from observed process or unit names; do not guess names.

### 5. Inspect recent errors

```bash
journalctl -p warning..alert -b --no-pager -n 100
```

For an identified service, inspect only its recent bounded logs:

```bash
journalctl -u <observed-service> --since '30 minutes ago' --no-pager -n 200
```

If the application runs in Docker, first use metadata-only checks:

```bash
docker ps --format 'table {{.Names}}\t{{.Image}}\t{{.Status}}\t{{.Ports}}'
docker stats --no-stream --format 'table {{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.MemPerc}}'
```

Read bounded container logs only after identifying the container:

```bash
docker logs --since 30m --tail 200 <observed-container>
```

Never read `/proc/*/environ`, run `printenv`, dump Docker environment/config metadata, or open application secret files during a routine audit.

### 6. Follow observed evidence

- Inspect a service, container, port, or log source only after it appears in an earlier check.
- Prefer status and bounded logs over broad recursive scans.
- If a command is unavailable or permission-denied, record the limitation and continue with non-privileged checks.
- Do not run `systemctl restart/stop/enable`, `service`, `docker exec`, `docker rm`, `docker compose up/down`, package managers, `kill`, `reboot`, file writes, cleanup commands, deployments, or migrations.

## Reporting contract

Return a concise report with:

1. **Target** — host, port, account, remote hostname, audit timestamp, and connection result.
2. **Executive summary** — overall status and the highest-risk observation.
3. **Findings** — severity (`critical`, `high`, `medium`, `low`, or `info`), evidence command/output, impact, and confidence.
4. **Healthy signals** — checks that passed and their relevant values.
5. **Coverage gaps** — unavailable commands, permission limits, and checks not run.
6. **Approval-needed actions** — possible remediations listed only; do not execute them.

Redact tokens, passwords, API keys, cookies, connection strings, and suspicious command arguments before quoting output. Distinguish observed facts from hypotheses, and include exact timestamps for transient findings.
