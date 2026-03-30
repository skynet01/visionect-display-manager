FROM python:3.11-slim-bookworm

RUN pip install --no-cache-dir argon2-cffi requests

COPY refresh.py /opt/refresh.py
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
