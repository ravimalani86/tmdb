import json
from pathlib import Path

import config

ROOT = Path(__file__).resolve().parent.parent


def load_provider_jobs() -> tuple[list[dict], str] | tuple[None, None]:
  """
  Returns list of {region, ids, names} for discover API, and monetization type.
  """
  if config.SYNC_PROVIDERS_FILE:
    path = Path(config.SYNC_PROVIDERS_FILE)
    if not path.is_absolute():
      path = ROOT / path
    if not path.exists():
      raise FileNotFoundError(f"Provider config not found: {path}")

    data = json.loads(path.read_text(encoding="utf-8"))
    by_region: dict[str, dict] = {}

    for item in data.get("providers", []):
      region = item["region"].upper()
      pid = str(item["tmdb_id"])
      name = item.get("name", pid)
      bucket = by_region.setdefault(region, {"ids": set(), "names": []})
      if pid not in bucket["ids"]:
        bucket["ids"].add(pid)
        bucket["names"].append(name)

    monetization = data.get("monetization", config.WITH_WATCH_MONETIZATION_TYPES)
    jobs = [
      {
        "region": region,
        "ids": "|".join(sorted(info["ids"], key=int)),
        "names": info["names"],
      }
      for region, info in sorted(by_region.items())
      if not config.SYNC_REGIONS or region in config.SYNC_REGIONS
    ]
    return jobs, monetization

  if config.SYNC_PROVIDER_IDS:
    return (
      [{"region": config.WATCH_REGION, "ids": config.SYNC_PROVIDER_IDS, "names": []}],
      config.WITH_WATCH_MONETIZATION_TYPES,
    )

  return None, None
