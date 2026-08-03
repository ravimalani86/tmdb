import json
from pathlib import Path

import config

ROOT = Path(__file__).resolve().parent.parent


def load_provider_jobs() -> tuple[list[dict], str] | tuple[None, None]:
  """
  Returns list of {region, ids, names} for discover API, and monetization type.
  One job per provider (not grouped by region).
  """
  if config.SYNC_PROVIDERS_FILE:
    path = Path(config.SYNC_PROVIDERS_FILE)
    if not path.is_absolute():
      path = ROOT / path
    if not path.exists():
      raise FileNotFoundError(f"Provider config not found: {path}")

    data = json.loads(path.read_text(encoding="utf-8"))
    monetization = data.get("monetization", config.WITH_WATCH_MONETIZATION_TYPES)
    jobs: list[dict] = []
    seen: set[tuple[str, str]] = set()

    for item in data.get("providers", []):
      region = item["region"].upper()
      if config.SYNC_REGIONS and region not in config.SYNC_REGIONS:
        continue
      pid = str(item["tmdb_id"])
      key = (region, pid)
      if key in seen:
        continue
      seen.add(key)
      jobs.append({
        "region": region,
        "ids": pid,
        "names": [item.get("name", pid)],
      })

    jobs.sort(key=lambda j: (j["region"], int(j["ids"])))
    return jobs, monetization

  if config.SYNC_PROVIDER_IDS:
    # Comma/pipe list → one job per id (same WATCH_REGION for each)
    raw = config.SYNC_PROVIDER_IDS.replace(",", "|")
    jobs = [
      {"region": config.WATCH_REGION, "ids": pid, "names": []}
      for pid in (p.strip() for p in raw.split("|"))
      if p.strip()
    ]
    return jobs, config.WITH_WATCH_MONETIZATION_TYPES

  return None, None
