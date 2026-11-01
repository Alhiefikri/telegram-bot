-- Local disposable fixture credentials, never production credentials.
-- Four runtime connections (HTTP + three workers) plus two diagnostic slots.
CREATE ROLE candidate_runtime LOGIN PASSWORD 'candidate-local-only'
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT CONNECTION LIMIT 6;
GRANT CONNECT ON DATABASE disposable TO candidate_runtime;
CREATE SCHEMA django_candidate AUTHORIZATION candidate_runtime;
ALTER ROLE candidate_runtime IN DATABASE disposable SET search_path = django_candidate;
REVOKE ALL ON SCHEMA public FROM PUBLIC;
REVOKE ALL ON SCHEMA public FROM candidate_runtime;
