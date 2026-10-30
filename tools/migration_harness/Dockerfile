FROM python:3.13-alpine
RUN apk add --no-cache iptables && adduser -D -u 10001 relay
COPY boundary.py /opt/harness/boundary.py
ENTRYPOINT ["python", "/opt/harness/boundary.py"]
