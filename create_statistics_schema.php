<?php

$host     = 'localhost';
$port     = 5432;
$dbname   = 'postgres';
$user     = 'postgres';
$password = 'postgres';

$schema   = 'paracetamol';
$interval = '3 month'; // Older records will be disregarded in the median test duration calculation

$pdo = new \PDO("pgsql:host={$host};port={$port};dbname={$dbname}", $user, $password);
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

$query = <<<HEREDOC
CREATE SCHEMA IF NOT EXISTS $schema;

CREATE TABLE IF NOT EXISTS $schema.test
(
    id bigserial,
    name text COLLATE pg_catalog."default" UNIQUE NOT NULL,
    created_at timestamp without time zone NOT NULL DEFAULT now(),
    CONSTRAINT test_pkey PRIMARY KEY (id)
);

CREATE TABLE IF NOT EXISTS $schema.environment
(
    id bigserial,
    name text COLLATE pg_catalog."default" UNIQUE NOT NULL,
    created_at timestamp without time zone NOT NULL DEFAULT now(),
    CONSTRAINT environment_pkey PRIMARY KEY (id)
);

CREATE TABLE IF NOT EXISTS $schema.project
(
    id bigserial,
    name text COLLATE pg_catalog."default" UNIQUE NOT NULL,
    created_at timestamp without time zone NOT NULL DEFAULT now(),
    CONSTRAINT project_pkey PRIMARY KEY (id)
);

CREATE TABLE IF NOT EXISTS $schema.duration
(
    id bigserial,
    project_id bigint NOT NULL,
    environment_id bigint NOT NULL,
    test_id bigint NOT NULL,
    duration_seconds integer NOT NULL,
    created_at timestamp without time zone NOT NULL DEFAULT now(),
    CONSTRAINT duration_pkey PRIMARY KEY (id),
    CONSTRAINT fk_project FOREIGN KEY (project_id)
        REFERENCES $schema.project (id) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE CASCADE,
    CONSTRAINT fk_envs FOREIGN KEY (environment_id)
        REFERENCES $schema.environment (id) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE CASCADE,
    CONSTRAINT fk_test FOREIGN KEY (test_id)
        REFERENCES $schema.test (id) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE CASCADE
);

CREATE INDEX pcml_duration_project_id ON $schema.duration (project_id);

CREATE INDEX pcml_duration_environment_id ON $schema.duration (environment_id);

CREATE INDEX pcml_duration_test_id ON $schema.duration (test_id);

CREATE INDEX pcml_duration_duration_seconds ON $schema.duration (duration_seconds);

CREATE INDEX pcml_duration_created_at ON $schema.duration (created_at);

CREATE OR REPLACE VIEW $schema.duration_median AS 
    SELECT 
        project_id,
        environment_id, 
        test_id, 
        PERCENTILE_DISC(0.5) WITHIN GROUP (ORDER BY duration_seconds DESC) AS median_duration_seconds 
    FROM $schema.duration 
    WHERE created_at > (NOW() - INTERVAL '$interval') 
    GROUP BY project_id, environment_id, test_id
;

HEREDOC;

$pdo->exec($query);

return 0;
