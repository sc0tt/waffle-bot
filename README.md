waffle-bot
==========

You will need the following in your Phergie prefs:
```php
'ai.settings' => array(
        'ignore' => array(''), //List of nicks to ignore, case insensitive 
        'reddit' => array("user" => "", "password" => "", "subreddit" => ""),
        'db' => array('host' => '','user' => '','pass' => '','name' => ''),
        'twitter' => array('CONSUMER_KEY' => '','CONSUMER_SECRET' => '','OAUTH_TOKEN' => '','OAUTH_SECRET' => '')
        ),
```
You will need a PostgreSQL database with the following table
```sql
CREATE SEQUENCE pair_id
    START WITH 0
    INCREMENT BY 1
    MINVALUE 0
    NO MAXVALUE
    CACHE 1;

CREATE TABLE pairs (
            id integer DEFAULT nextval('pair_id'::regclass) NOT NULL,
            key text NOT NULL,
            value text NOT NULL,
            nick text NOT NULL,
            "time" text NOT NULL
            );

ALTER TABLE ONLY pairs
    ADD CONSTRAINT pairs_pkey PRIMARY KEY (id);

CREATE INDEX key_index ON pairs USING btree (key);

CREATE INDEX value_key ON pairs USING btree (value);
```
