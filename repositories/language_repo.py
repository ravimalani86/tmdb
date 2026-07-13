from sqlalchemy.orm import Session

from models.genres import MediaSpokenLanguage, SpokenLanguage


def sync_spoken_languages(
    session: Session,
    media_type: str,
    media_id: int,
    languages: list,
) -> None:
    session.query(MediaSpokenLanguage).filter(
        MediaSpokenLanguage.media_type == media_type,
        MediaSpokenLanguage.media_id == media_id,
    ).delete(synchronize_session=False)

    for lang in languages:
        if not isinstance(lang, dict):
            continue
        iso = (lang.get("iso_639_1") or "").strip()
        if iso == "":
            continue

        spoken = session.query(SpokenLanguage).filter(SpokenLanguage.iso_code == iso).first()
        if spoken is None:
            spoken = SpokenLanguage(
                iso_code=iso,
                language_name=lang.get("name") or lang.get("english_name") or iso,
                english_name=lang.get("english_name"),
            )
            session.add(spoken)
            session.flush()
        else:
            name = lang.get("name") or lang.get("english_name")
            if name and spoken.language_name != name:
                spoken.language_name = name
            english = lang.get("english_name")
            if english and spoken.english_name != english:
                spoken.english_name = english

        session.add(
            MediaSpokenLanguage(
                media_type=media_type,
                media_id=media_id,
                language_id=spoken.id,
            )
        )
