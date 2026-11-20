# MatchApi PHP

This folder is a PHP 8.2 port of the REST backend in `crickBuzzBackend.zip`. It keeps the `/api` route families, SQL Server persistence model, admin JWT authentication, and cricket-data integrations. The .NET archive is unchanged.

## Requirements

- PHP 8.2 or newer
- Composer
- PHP extensions `pdo_sqlsrv`, `json`, and `mbstring`
- SQL Server

The project intentionally uses PHP/PDO directly and has no runtime Composer packages.

## Setup

1. Copy `.env.example` to `.env` and set the SQL Server, JWT, and provider values.
2. For a new database, run `database/schema.sql` against SQL Server. If using the existing .NET database, do not run the schema script; it already has the required tables.
3. Install/check the project metadata with `composer install`.
4. Start the PHP development server from this folder:

   ```sh
   php -S 127.0.0.1:8080 -t public
   ```

The root endpoint returns the service status. The OpenAPI document is available at `/openapi/v1.json`.

## Configuration

`DB_*` values connect PDO to SQL Server. `CRICAPI_BASE_URL` and `CRICAPI_KEY` enable `/api/matches/current` and `/api/matches/{matchId}`. `CRICBUZZ_BASE_URL` is used by the Cricbuzz match, scorecard, info, search, and commentary routes. Set `JWT_KEY` to a new random secret of at least 32 characters. Do not reuse secrets from the .NET `appsettings*.json` files; rotate any credentials that were committed or shared with the archive.

Admin registration creates an unapproved account. A database operator must promote the first trusted account by setting `IsApproved = 1, IsSuperAdmin = 1` before it can log in and approve other accounts. This mirrors the original approval flow while avoiding a hard-coded bootstrap credential.

## API coverage

The port includes admin registration/login/approval, teams, players, sports/roles, fixtures, fixture scores and scorecards, commentary, external current-match/detail/search/scorecard calls, and live Cricbuzz commentary. SQL writes use prepared statements and the fixture score operation is transactional.

The .NET SignalR hub protocol does not have a PHP equivalent in this dependency-free port. Commentary and score broadcast events can instead be delivered to optional HTTP webhook URLs via `COMMENTARY_BROADCAST_URL` and `SCORE_BROADCAST_URL`; leave these unset to disable webhook delivery. Existing clients that require SignalR must switch to an HTTP event consumer or add a compatible realtime service.

Authentication policy matches the source API: only super-admin approval/list endpoints require a JWT. Resource CRUD endpoints remain public as they are in the .NET route mappings. Admin passwords retain the existing salted SHA-256 representation for compatibility with the current database.

## Route list

| Method | Route |
|---|---|
| POST | `/api/admin/register`, `/api/admin/login` |
| GET | `/api/admin/approval-requests`, `/api/admin/all` |
| PUT | `/api/admin/approve/{id}` |
| GET, POST | `/api/teams` |
| PUT, DELETE | `/api/teams/{id}` |
| POST | `/api/players` |
| GET | `/api/players/team/{teamId}` |
| PUT, DELETE | `/api/players/{id}` |
| GET, POST | `/api/sports`, `/api/sports/roles` |
| GET | `/api/sports/{sportId}/roles` |
| POST | `/api/fixtures` |
| GET | `/api/fixtures/live`, `/api/fixtures/all`, `/api/fixtures/search` |
| GET, PATCH | `/api/fixtures/{fixtureId}` |
| PATCH | `/api/fixtures/{fixtureId}/score` |
| GET | `/api/fixtures/{fixtureId}/top-performers` |
| GET, POST | `/api/fixtures/{fixtureId}/commentary` |
| PATCH | `/api/fixtures/{fixtureId}/commentary/{commentaryId}` |
| GET | `/api/matches/current`, `/api/matches/{matchId}`, `/api/matches/search` |
| GET | `/api/matches/cricbuzz/current`, `/api/matches/cricbuzz/{matchId}/info`, `/api/matches/cricbuzz/{matchId}/scorecard` |
| GET | `/api/matches/{cricbuzzMatchId}/commentary` |

