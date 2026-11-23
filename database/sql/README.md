# Country / State / City data

`geography.sql` creates and fills `countries`, `states` and `cities` (MySQL 8 / MariaDB 10.4+):

- 250 countries with ISO code, calling code (`phone_code`) and the length range of a national mobile number
- 4,963 states / regions for every country
- 35,298 cities for India, USA, UK, Australia, Canada, UAE, Singapore, New Zealand, Pakistan,
  Bangladesh, Nepal, Sri Lanka, South Africa, Malaysia, Saudi Arabia, Qatar, Kuwait, Oman and Bahrain

## The normal way (Laravel)

```bash
php artisan migrate          # creates the tables and loads the data; also adds users.mobile_country_code
```

## By hand (MySQL)

```bash
mysql -u USER -p DATABASE < database/sql/geography.sql
mysql -u USER -p DATABASE -e "ALTER TABLE users ADD COLUMN mobile_country_code VARCHAR(8) NULL AFTER mobile;"
```

Use one or the other, not both (the SQL file drops and recreates the three tables).

## API (JSON)

| URL | Returns |
| --- | --- |
| `GET /geo/countries` | all countries |
| `GET /geo/countries/{id}/states` | the states of a country |
| `GET /geo/states/{id}/cities` | the cities of a state |
