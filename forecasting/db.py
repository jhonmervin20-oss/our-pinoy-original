"""
forecasting/db.py

Connection and transaction helpers. Credentials mirror config/database.php's
defaults and can be overridden by the same environment variables PHP uses, so
there is exactly one place the database is described.
"""

from __future__ import annotations

import os
from contextlib import contextmanager

import pandas as pd
from sqlalchemy import create_engine, text
from sqlalchemy.engine import Engine

_ENGINE: Engine | None = None


def engine() -> Engine:
    """Process-wide engine. Built once; SQLAlchemy pools the connections."""
    global _ENGINE
    if _ENGINE is None:
        host = os.getenv("DB_HOST", "localhost")
        name = os.getenv("DB_NAME", "restaurant_management_system_db")
        user = os.getenv("DB_USER", "root")
        pwd = os.getenv("DB_PASS", "")
        _ENGINE = create_engine(
            f"mysql+pymysql://{user}:{pwd}@{host}/{name}?charset=utf8mb4",
            pool_pre_ping=True,
            future=True,
        )
    return _ENGINE


def read_sql(sql: str, params: dict | None = None) -> pd.DataFrame:
    with engine().connect() as conn:
        return pd.read_sql(text(sql), conn, params=params or {})


def scalar(sql: str, params: dict | None = None):
    with engine().connect() as conn:
        return conn.execute(text(sql), params or {}).scalar()


def execute(sql: str, params: dict | list[dict] | None = None) -> int:
    """Single statement, committed. Accepts a list of dicts for executemany."""
    with engine().begin() as conn:
        result = conn.execute(text(sql), params or {})
        return result.rowcount


@contextmanager
def transaction():
    """
    Wraps a whole stage. A stage either lands completely or not at all -- a
    half-written forecast with no record is the one outcome this pipeline is
    not allowed to produce.
    """
    with engine().begin() as conn:
        yield conn
