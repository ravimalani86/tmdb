from __future__ import annotations

from dataclasses import dataclass
from datetime import date, datetime
from decimal import Decimal
from pathlib import Path

from pymysql.converters import escape_string
from sqlalchemy import inspect, text
from sqlalchemy.engine import Engine

from db import Base
from utils.logger import get_logger

logger = get_logger(__name__)

ROOT = Path(__file__).resolve().parent.parent
EXPORT_DIR = ROOT / "exports"
CURRENT_EXPORT_MARKER = EXPORT_DIR / ".current_export_dir"
BATCH_SIZE = 2000


def start_export_run() -> Path:
    """Create exports/YYYY-MM-DD_HH-MM-SS/ and mark it as the current run."""
    EXPORT_DIR.mkdir(parents=True, exist_ok=True)
    stamp = datetime.now().strftime("%Y-%m-%d_%H-%M-%S")
    run_dir = EXPORT_DIR / stamp
    run_dir.mkdir(parents=True, exist_ok=True)
    CURRENT_EXPORT_MARKER.write_text(str(run_dir.resolve()), encoding="utf-8")
    logger.info("Export run folder: %s", run_dir)
    return run_dir


def get_export_run_dir() -> Path:
    """Reuse current run folder if present; otherwise start a new one."""
    if CURRENT_EXPORT_MARKER.exists():
        raw = CURRENT_EXPORT_MARKER.read_text(encoding="utf-8").strip()
        if raw:
            run_dir = Path(raw)
            if run_dir.is_dir():
                return run_dir
    return start_export_run()


@dataclass(frozen=True)
class TableExport:
    filename: str
    table: str
    where: str | None = None
    group: str = ""
    description: str = ""


def _mt(media_type: str) -> str:
    return f"media_type = '{media_type}'"


def _tbl(filename: str, table: str, *, group: str, description: str, where: str | None = None) -> TableExport:
    return TableExport(filename=filename, table=table, where=where, group=group, description=description)


# ---------------------------------------------------------------------------
# One SQL file per table — API-used tables only (no user_media_state)
# ---------------------------------------------------------------------------
# Filenames use import-order prefix (01_…27_) so folder sort = import order.
OTHER_TABLES: tuple[TableExport, ...] = (
    _tbl("01_genres.sql", "genres", group="other", description="Genres (movie + tv)"),
    _tbl("02_spoken_languages.sql", "spoken_languages", group="other", description="Spoken languages lookup"),
    _tbl("03_keywords.sql", "keywords", group="other", description="Keywords lookup"),
    _tbl("04_watch_providers.sql", "watch_providers", group="other", description="Watch providers lookup"),
    _tbl("05_people.sql", "people", group="other", description="Cast/crew people"),
)

MOVIE_TABLES: tuple[TableExport, ...] = (
    _tbl("06_movies.sql", "movies", group="movies", description="Movie core rows"),
    _tbl("07_media_genres_movie.sql", "media_genres", group="movies", description="Movie ↔ genre links", where=_mt("movie")),
    _tbl("08_media_spoken_languages_movie.sql", "media_spoken_languages", group="movies", description="Movie ↔ language links", where=_mt("movie")),
    _tbl("09_credits_movie.sql", "credits", group="movies", description="Movie cast/crew", where=_mt("movie")),
    _tbl("10_media_watch_providers_movie.sql", "media_watch_providers", group="movies", description="Movie ↔ provider links", where=_mt("movie")),
    _tbl("11_similar_media_movie.sql", "similar_media", group="movies", description="Similar movies", where=_mt("movie")),
    _tbl("12_videos_movie.sql", "videos", group="movies", description="Movie videos/trailers", where=_mt("movie")),
    _tbl("13_media_keywords_movie.sql", "media_keywords", group="movies", description="Movie ↔ keyword links", where=_mt("movie")),
    _tbl("14_recommendations_movie.sql", "recommendations", group="movies", description="Movie recommendations", where=_mt("movie")),
    _tbl("15_images_movie.sql", "images", group="movies", description="Movie posters/backdrops (large — import last)", where=_mt("movie")),
)

TV_TABLES: tuple[TableExport, ...] = (
    _tbl("16_tv_shows.sql", "tv_shows", group="tv", description="TV show core rows"),
    _tbl("17_tv_seasons.sql", "tv_seasons", group="tv", description="TV seasons"),
    _tbl("18_tv_episodes.sql", "tv_episodes", group="tv", description="TV episodes"),
    _tbl("19_media_genres_tv.sql", "media_genres", group="tv", description="TV ↔ genre links", where=_mt("tv")),
    _tbl("20_media_spoken_languages_tv.sql", "media_spoken_languages", group="tv", description="TV ↔ language links", where=_mt("tv")),
    _tbl("21_credits_tv.sql", "credits", group="tv", description="TV cast/crew", where=_mt("tv")),
    _tbl("22_media_watch_providers_tv.sql", "media_watch_providers", group="tv", description="TV ↔ provider links", where=_mt("tv")),
    _tbl("23_similar_media_tv.sql", "similar_media", group="tv", description="Similar TV shows", where=_mt("tv")),
    _tbl("24_videos_tv.sql", "videos", group="tv", description="TV videos/trailers", where=_mt("tv")),
    _tbl("25_media_keywords_tv.sql", "media_keywords", group="tv", description="TV ↔ keyword links", where=_mt("tv")),
    _tbl("26_recommendations_tv.sql", "recommendations", group="tv", description="TV recommendations", where=_mt("tv")),
    _tbl("27_images_tv.sql", "images", group="tv", description="TV posters/backdrops (large — import last)", where=_mt("tv")),
)

IMPORT_ORDER: tuple[TableExport, ...] = (
    *OTHER_TABLES,
    *MOVIE_TABLES,
    *TV_TABLES,
)

# Unique physical tables needed on live server (18 + user_media_state empty for API writes)
LIVE_API_TABLES: tuple[str, ...] = (
    "genres",
    "spoken_languages",
    "keywords",
    "watch_providers",
    "people",
    "movies",
    "media_genres",
    "media_spoken_languages",
    "credits",
    "media_watch_providers",
    "similar_media",
    "videos",
    "media_keywords",
    "recommendations",
    "images",
    "tv_shows",
    "tv_seasons",
    "tv_episodes",
    "user_media_state",
)


def format_sql_value(value):
    if value is None:
        return "NULL"
    if isinstance(value, bool):
        return "1" if value else "0"
    if isinstance(value, (int, Decimal)) and not isinstance(value, bool):
        return str(value)
    if isinstance(value, float):
        return repr(value)
    if isinstance(value, datetime):
        return f"'{value.isoformat(sep=' ')}'"
    if isinstance(value, date):
        return f"'{value.isoformat()}'"
    if isinstance(value, bytes):
        return f"x'{value.hex()}'"
    escaped = escape_string(str(value))
    return f"'{escaped}'"


def ensure_sync_column(engine: Engine, table_name: str) -> None:
    inspector = inspect(engine)
    columns = [col["name"] for col in inspector.get_columns(table_name)]
    if "sync_with_cloud" in columns:
        return

    logger.info("Adding sync_with_cloud column to %s", table_name)
    alter_sql = text(
        f"ALTER TABLE `{table_name}` ADD COLUMN sync_with_cloud TINYINT(1) NOT NULL DEFAULT 0"
    )
    with engine.begin() as conn:
        conn.execute(alter_sql)


def _build_where_clause(extra_where: str | None) -> str:
    if extra_where:
        return f"sync_with_cloud = 0 AND ({extra_where})"
    return "sync_with_cloud = 0"


def export_table_rows(
    engine: Engine,
    table_name: str,
    out_file,
    extra_where: str | None = None,
) -> int:
    where_clause = _build_where_clause(extra_where)
    logger.info("Exporting %s", table_name)

    select_sql = text(f"SELECT * FROM `{table_name}` WHERE {where_clause}")
    update_sql = text(f"UPDATE `{table_name}` SET sync_with_cloud = 1 WHERE {where_clause}")

    row_count = 0
    columns: list[str] | None = None
    column_list = ""
    header_written = False

    with engine.connect() as conn:
        result = conn.execution_options(stream_results=True).execute(select_sql)

        while True:
            batch = result.fetchmany(BATCH_SIZE)
            if not batch:
                break

            if columns is None:
                columns = list(batch[0]._mapping.keys())
                column_list = ", ".join(f"`{col}`" for col in columns)

            if not header_written:
                out_file.write(f"-- Table: {table_name}\n")
                if extra_where:
                    out_file.write(f"-- Filter: {extra_where}\n")
                out_file.write(f"-- Generated on {datetime.utcnow().isoformat()} UTC\n\n")
                header_written = True

            for row in batch:
                mapping = row._mapping
                values = ", ".join(format_sql_value(mapping[col]) for col in columns)
                out_file.write(
                    f"INSERT INTO `{table_name}` ({column_list}) VALUES ({values});\n"
                )
                row_count += 1

            out_file.flush()

        result.close()

        if row_count == 0:
            logger.info("  no rows with sync_with_cloud = 0")
            return 0

        # Must commit explicitly: connect() autobegins a txn; nested begin()
        # only releases a savepoint, and connect() exit would otherwise roll back.
        conn.execute(update_sql)
        conn.commit()

    out_file.write("\n")
    logger.info("  exported %s rows and marked them synced", row_count)
    return row_count


def run_table_export(engine: Engine, export: TableExport, run_dir: Path) -> int:
    export_file = run_dir / export.filename
    export_file.parent.mkdir(parents=True, exist_ok=True)
    total_rows = 0

    with export_file.open("w", encoding="utf-8") as out_file:
        out_file.write(f"-- {export.filename}\n")
        out_file.write(f"-- {export.description}\n")
        out_file.write("-- Rows with sync_with_cloud = 0 only.\n")
        out_file.write("-- After export, exported rows are marked synced on local DB.\n\n")

        try:
            ensure_sync_column(engine, export.table)
            total_rows = export_table_rows(
                engine,
                export.table,
                out_file,
                extra_where=export.where,
            )
        except Exception as exc:
            logger.error("Failed exporting %s: %s", export.filename, exc)

    if total_rows == 0:
        export_file.unlink(missing_ok=True)
        logger.info("Skip %s — 0 rows (no file)", export.filename)
        return 0

    logger.info("File %s — %s rows", export.filename, total_rows)
    return total_rows


def run_group_export(
    engine: Engine,
    exports: tuple[TableExport, ...],
    run_dir: Path | None = None,
) -> int:
    if run_dir is None:
        run_dir = get_export_run_dir()
    total = 0
    written = 0
    for export in exports:
        logger.info("=== %s ===", export.filename)
        rows = run_table_export(engine, export, run_dir)
        total += rows
        if rows > 0:
            written += 1
    logger.info(
        "Group export — %s rows in %s file(s) (%s skipped empty)",
        total,
        written,
        len(exports) - written,
    )
    return total


def write_import_index(run_dir: Path | None = None) -> Path:
    """Write table-wise import index + batch scripts into the export run folder."""
    if run_dir is None:
        run_dir = get_export_run_dir()
    run_dir.mkdir(parents=True, exist_ok=True)
    import_index = run_dir / "import_index.txt"
    index_sql = run_dir / "import_index.sql"
    import_bat = run_dir / "import_all.bat"
    import_sh = run_dir / "import_all.sh"

    lines = [
        "TMDB LIVE IMPORT INDEX (table-wise)",
        "===================================",
        f"Generated: {datetime.utcnow().isoformat()} UTC",
        f"Export folder: {run_dir}",
        "",
        f"Physical tables on live server: {len(LIVE_API_TABLES)}",
        f"Import SQL files (steps): {len(IMPORT_ORDER)}",
        "",
        "Run one .sql file at a time in order below.",
        "If a step fails, fix and re-run only that file.",
        "",
        f"{'Step':<6} {'SQL file':<42} {'Target table':<25} {'Group':<8} Status",
        "-" * 102,
    ]

    sql_lines = [
        "-- TMDB live import index (table-wise)",
        "-- Open MySQL client, cd to this export folder, then:",
        "--   SOURCE import_index.sql;",
        "-- Or run: import_all.bat (Windows) / bash import_all.sh (Linux)",
        "",
    ]

    bat_lines = [
        "@echo off",
        "REM TMDB table-wise import — run from this export folder",
        "cd /d \"%~dp0\"",
        "set MYSQL=C:\\xampp\\mysql\\bin\\mysql.exe",
        "set DB=tmdbdata",
        "set USER=root",
        "REM set PASS=-pYourPassword",
        "echo TMDB import starting...",
        "",
    ]

    sh_lines = [
        "#!/usr/bin/env bash",
        "# TMDB table-wise import — run from this export folder",
        "set -euo pipefail",
        "cd \"$(dirname \"$0\")\"",
        "DB=\"${DB_NAME:-tmdbdata}\"",
        "USER=\"${DB_USER:-root}\"",
        "MYSQL=\"${MYSQL_BIN:-mysql}\"",
        "echo \"TMDB import starting...\"",
        "",
        "run_sql() {",
        "  local file=\"$1\"",
        "  if [[ -n \"${DB_PASS:-}\" ]]; then",
        "    \"$MYSQL\" -u \"$USER\" -p\"$DB_PASS\" \"$DB\" < \"$file\"",
        "  else",
        "    \"$MYSQL\" -u \"$USER\" \"$DB\" < \"$file\"",
        "  fi",
        "}",
        "",
    ]

    current_group = ""
    step = 1
    for export in IMPORT_ORDER:
        if export.group != current_group:
            current_group = export.group
            lines.append("")
            lines.append(f"--- {current_group.upper()} ---")
            lines.append("")

        filepath = run_dir / export.filename
        exists = filepath.exists()
        size_note = ""
        if exists:
            size_mb = filepath.stat().st_size / (1024 * 1024)
            if size_mb >= 1:
                size_note = f" ({size_mb:.1f} MB)"

        status = "ready" if exists else "missing"
        where_note = f" [{export.where}]" if export.where else ""
        lines.append(
            f"{step:02d}     {export.filename:<42} {export.table:<25} {export.group:<8} {status}{size_note}"
        )
        if export.where:
            lines.append(f"       filter: {export.where}")

        sql_lines.append(f"-- {step:02d}. {export.table}{where_note}  <-  {export.filename}")
        if exists:
            sql_lines.append(f"SOURCE {export.filename};")
        else:
            sql_lines.append(f"-- SOURCE {export.filename};  -- not exported yet")
        sql_lines.append("")

        bat_lines.append(f"echo [{step:02d}/{len(IMPORT_ORDER)}] {export.filename} -> {export.table}")
        bat_lines.append(f"if exist \"{export.filename}\" (")
        bat_lines.append(f"  \"%MYSQL%\" -u %USER% %DB% < \"{export.filename}\"")
        bat_lines.append(") else (")
        bat_lines.append(f"  echo SKIP missing {export.filename}")
        bat_lines.append(")")
        bat_lines.append("")

        sh_lines.append(f"echo \"[{step:02d}/{len(IMPORT_ORDER)}] {export.filename} -> {export.table}\"")
        sh_lines.append(f"if [[ -f \"{export.filename}\" ]]; then")
        sh_lines.append(f"  run_sql \"{export.filename}\"")
        sh_lines.append("else")
        sh_lines.append(f"  echo \"SKIP missing {export.filename}\"")
        sh_lines.append("fi")
        sh_lines.append("")

        step += 1

    lines.extend([
        "",
        "=" * 102,
        "",
        "PHYSICAL TABLES ON LIVE SERVER (19):",
        "  " + ", ".join(LIVE_API_TABLES),
        "",
        "NOT imported (local sync only):",
        "  collections, external_ids, translations, certifications,",
        "  production_countries, media_production_countries,",
        "  sync_checkpoint, sync_metrics, rate_limit_tracker, retry_queue",
        "",
        "user_media_state — empty on live, API writes directly (no export/import)",
        "",
        "SINGLE FILE IMPORT (example):",
        "  mysql -u root tmdbdata < 01_genres.sql",
        "",
        "IMPORT ALL (auto, in order):",
        "  Windows:  import_all.bat",
        "  Linux:    bash import_all.sh",
        "  MySQL:    SOURCE import_index.sql;",
        "",
        "LARGE FILES — import last:",
        "  15_images_movie.sql",
        "  27_images_tv.sql",
    ])

    bat_lines.append("echo Import done.")
    sh_lines.append("echo Import done.")

    import_index.write_text("\n".join(lines) + "\n", encoding="utf-8")
    index_sql.write_text("\n".join(sql_lines) + "\n", encoding="utf-8")
    import_bat.write_text("\n".join(bat_lines) + "\n", encoding="utf-8")
    import_sh.write_text("\n".join(sh_lines) + "\n", encoding="utf-8")

    logger.info("Import index written to %s", import_index)
    return import_index
