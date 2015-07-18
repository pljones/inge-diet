SELECT
    (
        SELECT
            COUNT(DISTINCT f.id)
        FROM
            foods        f,
            diet_entries d
        WHERE
            f.id = d.food_id
    )
    -
    (
        SELECT
            COUNT(DISTINCT f.id)
        FROM
            foods        f,
            diet_entries d
        WHERE
            f.id         = d.food_id AND
            (
                d.entry_date < ? OR
                d.entry_date > ?
            )
    )
    AS count
LIMIT 1
