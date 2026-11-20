<?php
declare(strict_types=1);

namespace MatchApi;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use Throwable;

final class Api
{
    public function __construct(private readonly Database $db)
    {
    }

    public function dispatch(): never
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = '/' . trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
        if ($path === '//') {
            $path = '/';
        }

        if ($method === 'GET' && $path === '/') {
            Http::json(['service' => 'MatchApi', 'status' => 'running']);
        }
        if ($method === 'GET' && $path === '/openapi/v1.json') {
            Http::json($this->openApiDocument());
        }

        if ($path === '/api/admin/register' && $method === 'POST') {
            $this->registerAdmin();
        }
        if ($path === '/api/admin/login' && $method === 'POST') {
            $this->loginAdmin();
        }
        if ($path === '/api/admin/approval-requests' && $method === 'GET') {
            Jwt::requireSuperAdmin();
            Http::json($this->db->all('SELECT Id AS id, FirstName AS firstName, LastName AS lastName, Email AS email, IsApproved AS isApproved, CreatedAtUtc AS createdAtUtc FROM AdminUsers WHERE IsApproved = 0 ORDER BY CreatedAtUtc'));
        }
        if ($path === '/api/admin/all' && $method === 'GET') {
            Jwt::requireSuperAdmin();
            Http::json($this->db->all('SELECT Id AS id, FirstName AS firstName, LastName AS lastName, Email AS email, IsApproved AS isApproved, IsSuperAdmin AS isSuperAdmin, CreatedAtUtc AS createdAtUtc FROM AdminUsers ORDER BY CreatedAtUtc'));
        }
        if (preg_match('#^/api/admin/approve/([^/]+)$#', $path, $match) && $method === 'PUT') {
            Jwt::requireSuperAdmin();
            $id = $this->uuid($match[1], 'id');
            $updated = $this->db->execute('UPDATE AdminUsers SET IsApproved = 1, UpdatedAtUtc = SYSUTCDATETIME() WHERE Id = :id AND IsApproved = 0', ['id' => $id]);
            if ($updated === 0) {
                Http::problem('Admin user was not found or is already approved.', 404);
            }
            Http::json(['message' => 'Admin approved successfully.']);
        }

        if ($path === '/api/teams' || $path === '/api/teams/') {
            if ($method === 'GET') {
                $this->getTeams();
            }
            if ($method === 'POST') {
                $this->createTeam();
            }
        }
        if (preg_match('#^/api/teams/([^/]+)$#', $path, $match)) {
            if ($method === 'PUT') {
                $this->updateTeam($match[1]);
            }
            if ($method === 'DELETE') {
                $this->deleteTeam($match[1]);
            }
        }

        if (preg_match('#^/api/players/team/([^/]+)$#', $path, $match) && $method === 'GET') {
            $this->getPlayers($match[1]);
        }
        if ($path === '/api/players' || $path === '/api/players/') {
            if ($method === 'POST') {
                $this->createPlayer();
            }
        }
        if (preg_match('#^/api/players/([^/]+)$#', $path, $match)) {
            if ($method === 'PUT') {
                $this->updatePlayer($match[1]);
            }
            if ($method === 'DELETE') {
                $this->deletePlayer($match[1]);
            }
        }

        if ($path === '/api/sports' || $path === '/api/sports/') {
            if ($method === 'GET') {
                Http::json($this->db->all('SELECT Id AS id, Name AS name, Description AS description FROM Sports ORDER BY Name'));
            }
            if ($method === 'POST') {
                $this->createSport();
            }
        }
        if ($path === '/api/sports/roles' && $method === 'GET') {
            Http::json($this->db->all('SELECT Id AS id, SportId AS sportId, RoleName AS roleName, Description AS description FROM SportRoles ORDER BY RoleName'));
        }
        if ($path === '/api/sports/roles' && $method === 'POST') {
            $this->createSportRole();
        }
        if (preg_match('#^/api/sports/([^/]+)/roles$#', $path, $match) && $method === 'GET') {
            $sportId = $this->uuid($match[1], 'sportId');
            Http::json($this->db->all('SELECT Id AS id, SportId AS sportId, RoleName AS roleName, Description AS description FROM SportRoles WHERE SportId = :sportId ORDER BY RoleName', ['sportId' => $sportId]));
        }

        if (preg_match('#^/api/fixtures/([^/]+)/commentary(?:/([^/]+))?$#', $path, $match)) {
            $fixtureId = $this->uuid($match[1], 'fixtureId');
            if (!isset($match[2]) || $match[2] === '') {
                if ($method === 'GET') {
                    $this->getCommentary($fixtureId);
                }
                if ($method === 'POST') {
                    $this->createCommentary($fixtureId);
                }
            } elseif ($method === 'PATCH') {
                $this->updateCommentary($fixtureId, $match[2]);
            }
        }

        if ($path === '/api/fixtures/live' && $method === 'GET') {
            Http::json($this->fixtures('WHERE f.Status = :status', ['status' => 'Live']));
        }
        if ($path === '/api/fixtures/all' && $method === 'GET') {
            Http::json($this->fixtures('', []));
        }
        if ($path === '/api/fixtures/search' && $method === 'GET') {
            $term = trim((string) ($_GET['searchTerm'] ?? ''));
            $like = '%' . $term . '%';
            Http::json($this->searchFixtures([
                'home' => $like,
                'away' => $like,
                'sport' => $like,
            ]));
        }
        if (preg_match('#^/api/fixtures/([^/]+)/top-performers$#', $path, $match) && $method === 'GET') {
            $this->topPerformers($match[1]);
        }
        if (preg_match('#^/api/fixtures/([^/]+)/score$#', $path, $match) && $method === 'PATCH') {
            $this->updateFixtureScore($match[1]);
        }
        if (preg_match('#^/api/fixtures/([^/]+)$#', $path, $match)) {
            if ($method === 'GET') {
                $this->getFixtureDetails($match[1]);
            }
            if ($method === 'PATCH') {
                $this->updateFixture($match[1]);
            }
        }
        if (($path === '/api/fixtures' || $path === '/api/fixtures/') && $method === 'POST') {
            $this->createFixture();
        }

        if ($path === '/api/matches/current' && $method === 'GET') {
            $this->external('cricapi', 'currentMatches', ['offset' => (int) ($_GET['offset'] ?? 0)]);
        }
        if ($path === '/api/matches/search' && $method === 'GET') {
            $this->searchCricbuzzMatches((string) ($_GET['searchText'] ?? ''));
        }
        if (preg_match('#^/api/matches/cricbuzz/current$#', $path) && $method === 'GET') {
            $this->external('cricbuzz', 'api/home');
        }
        if (preg_match('#^/api/matches/cricbuzz/(\d+)/scorecard$#', $path, $match) && $method === 'GET') {
            $this->external('cricbuzz', 'api/mcenter/scorecard/' . $match[1]);
        }
        if (preg_match('#^/api/matches/cricbuzz/(\d+)/info$#', $path, $match) && $method === 'GET') {
            $this->external('cricbuzz', 'api/mcenter/comm/' . $match[1]);
        }
        if (preg_match('#^/api/matches/(\d+)/commentary$#', $path, $match) && $method === 'GET') {
            $this->cricbuzzCommentary((int) $match[1]);
        }
        if (preg_match('#^/api/matches/([^/]+)$#', $path, $match) && $method === 'GET') {
            $key = getenv('CRICAPI_KEY') ?: '';
            if ($key === '') {
                Http::problem('CRICAPI_KEY is not configured.', 503);
            }
            $this->external('cricapi', 'match_scorecard', ['id' => $match[1]]);
        }

        Http::problem('The requested endpoint was not found.', 404);
    }

    private function registerAdmin(): never
    {
        $data = Http::body();
        $first = trim((string) $this->field($data, 'firstName', ''));
        $last = trim((string) $this->field($data, 'lastName', ''));
        $email = strtolower(trim((string) $this->field($data, 'email', '')));
        $password = (string) $this->field($data, 'password', '');
        $errors = [];
        if ($first === '') {
            $errors['firstName'] = ['First name is required.'];
        }
        if ($last === '') {
            $errors['lastName'] = ['Last name is required.'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = ['A valid email address is required.'];
        }
        if (strlen($password) < 8) {
            $errors['password'] = ['Password must contain at least 8 characters.'];
        }
        if ($errors !== []) {
            Http::json(['errors' => $errors], 400);
        }
        if ($this->db->one('SELECT Id FROM AdminUsers WHERE Email = :email', ['email' => $email]) !== null) {
            Http::problem('Email already exists.', 409);
        }
        $id = $this->newId();
        $salt = $this->newId();
        $hash = base64_encode(hash('sha256', $password . $salt, true));
        $this->db->execute(
            'INSERT INTO AdminUsers (Id, FirstName, LastName, Email, PasswordHash, PasswordSalt, IsApproved, IsSuperAdmin, CreatedAtUtc) VALUES (:id, :first, :last, :email, :hash, :salt, 0, 0, SYSUTCDATETIME())',
            ['id' => $id, 'first' => $first, 'last' => $last, 'email' => $email, 'hash' => $hash, 'salt' => $salt]
        );
        Http::json(['id' => $id, 'message' => 'Admin registered successfully'], 200);
    }

    private function loginAdmin(): never
    {
        $data = Http::body();
        $email = strtolower(trim((string) $this->field($data, 'email', '')));
        $password = (string) $this->field($data, 'password', '');
        $admin = $this->db->one('SELECT Id, FirstName, LastName, Email, PasswordHash, PasswordSalt, IsApproved, IsSuperAdmin FROM AdminUsers WHERE Email = :email', ['email' => $email]);
        if ($admin === null || !hash_equals((string) $admin['PasswordHash'], base64_encode(hash('sha256', $password . $admin['PasswordSalt'], true)))) {
            Http::problem('Invalid email or password.', 401);
        }
        if (!(bool) $admin['IsApproved']) {
            Http::problem('Admin account is not approved.', 403);
        }
        $role = (bool) $admin['IsSuperAdmin'] ? 'SuperAdmin' : 'Admin';
        Http::json([
            'token' => Jwt::create((string) $admin['Id'], (string) $admin['Email'], $role),
            'adminId' => $admin['Id'],
            'firstName' => $admin['FirstName'],
            'lastName' => $admin['LastName'],
            'email' => $admin['Email'],
            'role' => $role,
        ]);
    }

    private function getTeams(): never
    {
        $teams = $this->db->all(
            'SELECT t.Id, t.Name, t.SportId, t.ColorHex, s.Name AS SportName, s.Description AS SportDescription FROM Teams t JOIN Sports s ON s.Id = t.SportId ORDER BY t.Name'
        );
        foreach ($teams as &$team) {
            $team['players'] = $this->db->all(
                'SELECT p.Name AS playerName, r.RoleName AS role, p.Id AS playerId, r.Id AS roleId FROM Players p JOIN SportRoles r ON r.Id = p.SportRoleId WHERE p.TeamId = :teamId ORDER BY p.Name',
                ['teamId' => $team['Id']]
            );
            $team = [
                'id' => $team['Id'],
                'teamName' => $team['Name'],
                'sportId' => $team['SportId'],
                'color' => $team['ColorHex'],
                'sport' => ['name' => $team['SportName'], 'description' => $team['SportDescription']],
                'players' => $team['players'],
            ];
        }
        Http::json($teams);
    }

    private function createTeam(): never
    {
        $data = Http::body();
        $name = trim((string) $this->field($data, 'name', ''));
        $sportId = $this->uuid((string) $this->field($data, 'sportId', ''), 'sportId');
        $color = trim((string) $this->field($data, 'colorHex', ''));
        if ($name === '' || $color === '') {
            Http::problem('Team name and colorHex are required.', 400);
        }
        if ($this->db->one('SELECT Id FROM Teams WHERE SportId = :sport AND Name = :name', ['sport' => $sportId, 'name' => $name]) !== null) {
            Http::json(['data' => null, 'success' => false, 'message' => 'Team already exists for the given sport.', 'error' => ['message' => 'Team already exists for the given sport.', 'statusCode' => 'BadRequest']]);
        }
        $id = $this->newId();
        $this->db->execute('INSERT INTO Teams (Id, Name, SportId, ColorHex, CreatedAtUtc) VALUES (:id, :name, :sport, :color, SYSUTCDATETIME())', [
            'id' => $id, 'name' => $name, 'sport' => $sportId, 'color' => $color,
        ]);
        Http::json(['data' => $id, 'success' => true, 'message' => 'Team created successfully.', 'error' => null]);
    }

    private function updateTeam(string $routeId): never
    {
        $id = $this->uuid($routeId, 'id');
        $data = Http::body();
        if (strtolower((string) $this->field($data, 'id', '')) !== strtolower($id)) {
            Http::problem('Route Id and Body Id must match.', 400);
        }
        $name = trim((string) $this->field($data, 'name', ''));
        $sportId = $this->uuid((string) $this->field($data, 'sportId', ''), 'sportId');
        $color = trim((string) $this->field($data, 'colorHex', ''));
        if ($name === '' || $color === '') {
            Http::problem('Name and colorHex are required.', 400);
        }
        $affected = $this->db->execute('UPDATE Teams SET Name = :name, SportId = :sport, ColorHex = :color, UpdatedAtUtc = SYSUTCDATETIME() WHERE Id = :id', [
            'name' => $name, 'sport' => $sportId, 'color' => $color, 'id' => $id,
        ]);
        if ($affected === 0 && $this->db->one('SELECT Id FROM Teams WHERE Id = :id', ['id' => $id]) === null) {
            Http::problem('Team not found.', 404);
        }
        Http::json(['data' => true, 'success' => true, 'message' => 'Team updated successfully.', 'error' => null]);
    }

    private function deleteTeam(string $routeId): never
    {
        $id = $this->uuid($routeId, 'id');
        try {
            $deleted = $this->db->execute('DELETE FROM Teams WHERE Id = :id', ['id' => $id]);
        } catch (PDOException $exception) {
            if (($exception->errorInfo[0] ?? $exception->getCode()) === '23000') {
                Http::problem('Team cannot be deleted while it is referenced by a fixture.', 409);
            }
            throw $exception;
        }
        if ($deleted === 0) {
            Http::problem('Team not found.', 404);
        }
        Http::json(['data' => true, 'success' => true, 'message' => 'Team deleted successfully.', 'error' => null]);
    }

    private function getPlayers(string $teamId): never
    {
        $id = $this->uuid($teamId, 'teamId');
        Http::json($this->db->all(
            'SELECT p.Id AS id, p.Name AS playerName, p.TeamId AS teamId, p.SportRoleId AS sportRoleId, r.RoleName AS role FROM Players p JOIN SportRoles r ON r.Id = p.SportRoleId WHERE p.TeamId = :id ORDER BY p.Name',
            ['id' => $id]
        ));
    }

    private function createPlayer(): never
    {
        $data = Http::body();
        $name = trim((string) $this->field($data, 'playerName', ''));
        $teamId = $this->uuid((string) $this->field($data, 'teamId', ''), 'teamId');
        $roleId = $this->uuid((string) $this->field($data, 'sportRoleId', ''), 'sportRoleId');
        if ($name === '') {
            Http::problem('PlayerName is required.', 400);
        }
        if ($this->db->one('SELECT Id FROM Players WHERE TeamId = :team AND Name = :name', ['team' => $teamId, 'name' => $name]) !== null) {
            Http::json(['data' => null, 'success' => false, 'message' => 'Player already exists for the given team.', 'error' => null]);
        }
        $team = $this->db->one('SELECT s.Name AS SportName FROM Teams t JOIN Sports s ON s.Id = t.SportId WHERE t.Id = :team', ['team' => $teamId]);
        if ($team === null) {
            Http::problem('Team not found.', 400);
        }
        if (strcasecmp((string) $team['SportName'], 'Cricket') === 0) {
            $playerCount = $this->db->one('SELECT COUNT(*) AS PlayerCount FROM Players WHERE TeamId = :team', ['team' => $teamId]);
            if ((int) ($playerCount['PlayerCount'] ?? 0) >= 11) {
                Http::json(['data' => null, 'success' => false, 'message' => 'Maximum Players Reached. A team can have a maximum of 11 players.', 'error' => null]);
            }
        }
        $id = $this->newId();
        $this->db->execute('INSERT INTO Players (Id, Name, TeamId, SportRoleId, CreatedAtUtc) VALUES (:id, :name, :team, :role, SYSUTCDATETIME())', [
            'id' => $id, 'name' => $name, 'team' => $teamId, 'role' => $roleId,
        ]);
        Http::json(['data' => $id, 'success' => true, 'message' => 'Player added successfully.', 'error' => null]);
    }

    private function updatePlayer(string $routeId): never
    {
        $id = $this->uuid($routeId, 'id');
        $data = Http::body();
        if (strtolower((string) $this->field($data, 'playerId', '')) !== strtolower($id)) {
            Http::problem('Route Id and Body Id must match.', 400);
        }
        $name = trim((string) $this->field($data, 'playerName', ''));
        $teamId = $this->uuid((string) $this->field($data, 'teamId', ''), 'teamId');
        $roleId = $this->uuid((string) $this->field($data, 'sportRoleId', ''), 'sportRoleId');
        if ($name === '') {
            Http::problem('PlayerName is required.', 400);
        }
        $this->db->execute('UPDATE Players SET Name = :name, TeamId = :team, SportRoleId = :role, UpdatedAtUtc = SYSUTCDATETIME() WHERE Id = :id', [
            'name' => $name, 'team' => $teamId, 'role' => $roleId, 'id' => $id,
        ]);
        if ($this->db->one('SELECT Id FROM Players WHERE Id = :id', ['id' => $id]) === null) {
            Http::problem('Player not found.', 404);
        }
        Http::json(['data' => true, 'success' => true, 'message' => 'Player updated successfully.', 'error' => null]);
    }

    private function deletePlayer(string $routeId): never
    {
        $id = $this->uuid($routeId, 'id');
        try {
            $deleted = $this->db->execute('DELETE FROM Players WHERE Id = :id', ['id' => $id]);
        } catch (PDOException $exception) {
            if (($exception->errorInfo[0] ?? $exception->getCode()) === '23000') {
                Http::problem('Player cannot be deleted while referenced by commentary or a scorecard.', 409);
            }
            throw $exception;
        }
        if ($deleted === 0) {
            Http::problem('Player not found.', 404);
        }
        Http::json(['data' => true, 'success' => true, 'message' => 'Player deleted successfully.', 'error' => null]);
    }

    private function createSport(): never
    {
        $data = Http::body();
        $name = trim((string) $this->field($data, 'name', ''));
        $description = trim((string) $this->field($data, 'description', ''));
        if ($name === '' || $description === '') {
            Http::problem('Name and description are required.', 400);
        }
        $id = $this->newId();
        $this->db->execute('INSERT INTO Sports (Id, Name, Description, CreatedAtUtc) VALUES (:id, :name, :description, SYSUTCDATETIME())', [
            'id' => $id, 'name' => $name, 'description' => $description,
        ]);
        Http::json(['data' => $id, 'success' => true, 'message' => 'Sport created successfully.', 'error' => null]);
    }

    private function createSportRole(): never
    {
        $data = Http::body();
        $sportId = $this->uuid((string) $this->field($data, 'sportId', ''), 'sportId');
        $name = trim((string) $this->field($data, 'roleName', ''));
        $description = trim((string) $this->field($data, 'description', ''));
        if ($name === '') {
            Http::problem('RoleName is required.', 400);
        }
        $id = $this->newId();
        $this->db->execute('INSERT INTO SportRoles (Id, SportId, RoleName, Description, CreatedAtUtc) VALUES (:id, :sport, :name, :description, SYSUTCDATETIME())', [
            'id' => $id, 'sport' => $sportId, 'name' => $name, 'description' => $description,
        ]);
        Http::json(['data' => $id, 'success' => true, 'message' => 'Sport role created successfully.', 'error' => null]);
    }

    private function createFixture(): never
    {
        $data = Http::body();
        $homeId = $this->uuid((string) $this->field($data, 'homeTeamId', ''), 'homeTeamId');
        $awayId = $this->uuid((string) $this->field($data, 'awayTeamId', ''), 'awayTeamId');
        $scheduled = (string) $this->field($data, 'scheduledAtUtc', '');
        $overs = trim((string) $this->field($data, 'totalOvers', ''));
        if ($homeId === $awayId) {
            Http::problem('Home team and away team cannot be the same.', 400);
        }
        try {
            $scheduledAt = new DateTimeImmutable($scheduled);
        } catch (\Exception) {
            Http::problem('ScheduledAtUtc must be a valid date.', 400);
        }
        if ($scheduledAt <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
            Http::problem('Scheduled date must be in the future.', 400);
        }
        $teams = $this->db->all(
            'SELECT t.Id, t.Name, t.SportId, s.Name AS SportName FROM Teams t JOIN Sports s ON s.Id = t.SportId WHERE t.Id IN (:home, :away)',
            ['home' => $homeId, 'away' => $awayId]
        );
        if (count($teams) !== 2) {
            Http::problem('Both teams must exist.', 400);
        }
        $teamsById = [];
        foreach ($teams as $team) {
            $teamsById[strtolower((string) $team['Id'])] = $team;
        }
        $homeTeam = $teamsById[strtolower($homeId)];
        $awayTeam = $teamsById[strtolower($awayId)];
        if (strtolower((string) $homeTeam['SportId']) !== strtolower((string) $awayTeam['SportId'])) {
            Http::problem('Both teams must play the same sport.', 400);
        }
        $id = $this->newId();
        $tracksWickets = strcasecmp((string) $homeTeam['SportName'], 'Cricket') === 0;
        $this->db->execute(
            'INSERT INTO Fixtures (Id, HomeTeamId, AwayTeamId, SportId, ScheduledAtUtc, Status, Phase, HomeScore, HomeWickets, HomeScore_Overs, AwayScore, AwayWickets, AwayScore_Overs, TotalOvers, CreatedAtUtc) VALUES (:id, :home, :away, :sport, :scheduled, :status, NULL, 0, :homeWickets, :homeOvers, 0, :awayWickets, :awayOvers, :totalOvers, SYSUTCDATETIME())',
            [
                'id' => $id,
                'home' => $homeId,
                'away' => $awayId,
                'sport' => $homeTeam['SportId'],
                'scheduled' => $scheduledAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
                'status' => 'Scheduled',
                'homeWickets' => $tracksWickets ? 0 : null,
                'homeOvers' => '0.0',
                'awayWickets' => $tracksWickets ? 0 : null,
                'awayOvers' => '0.0',
                'totalOvers' => $overs !== '' ? $overs : null,
            ]
        );
        Http::json([
            'id' => $id,
            'homeTeamId' => $homeId,
            'homeTeamName' => $homeTeam['Name'],
            'awayTeamId' => $awayId,
            'awayTeamName' => $awayTeam['Name'],
            'sportId' => $homeTeam['SportId'],
            'scheduledAtUtc' => $scheduledAt->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
            'status' => 'Scheduled',
        ], 201);
    }

    private function fixtures(string $where, array $parameters): array
    {
        return array_map(fn (array $row): array => $this->fixtureDto($row), $this->db->all(
            'SELECT f.*, h.Name AS HomeTeamName, a.Name AS AwayTeamName, s.Name AS SportName FROM Fixtures f JOIN Teams h ON h.Id = f.HomeTeamId JOIN Teams a ON a.Id = f.AwayTeamId JOIN Sports s ON s.Id = f.SportId ' . $where . ' ORDER BY f.ScheduledAtUtc DESC',
            $parameters
        ));
    }

    private function searchFixtures(array $parameters): array
    {
        return $this->db->all(
            'SELECT f.Id AS id, f.HomeTeamId AS homeTeamId, h.Name AS homeTeamName, f.AwayTeamId AS awayTeamId, a.Name AS awayTeamName, f.SportId AS sportId, s.Name AS sport, f.ScheduledAtUtc AS scheduledAtUtc, f.Status AS status, f.HomeScore AS homeScore, COALESCE(f.HomeWickets, 0) AS homeWickets, f.AwayScore AS awayScore, COALESCE(f.AwayWickets, 0) AS awayWickets FROM Fixtures f JOIN Teams h ON h.Id = f.HomeTeamId JOIN Teams a ON a.Id = f.AwayTeamId JOIN Sports s ON s.Id = f.SportId WHERE h.Name LIKE :home OR a.Name LIKE :away OR s.Name LIKE :sport ORDER BY f.ScheduledAtUtc DESC',
            $parameters
        );
    }

    private function getFixtureDetails(string $routeId): never
    {
        $id = $this->uuid($routeId, 'fixtureId');
        $row = $this->fixtureRow($id);
        if ($row === null) {
            Http::problem('Fixture not found.', 404);
        }
        $dto = $this->fixtureDto($row);
        $dto['commentary'] = $this->commentaryRows($id);
        $dto['topPerformers'] = $this->performerRows($id);
        $dto['scorecards'] = $this->scorecards($id);
        unset($dto['sportId']);
        Http::json($dto);
    }

    private function updateFixture(string $routeId): never
    {
        $id = $this->uuid($routeId, 'fixtureId');
        $data = Http::body();
        $fixture = $this->fixtureRow($id);
        if ($fixture === null) {
            Http::problem('Fixture not found.', 404);
        }
        $status = $this->enumValue($this->field($data, 'status'), [
            0 => 'Scheduled', 1 => 'Live', 2 => 'Completed', 3 => 'Postponed', 4 => 'Cancelled',
        ]);
        $phase = $this->enumValue($this->field($data, 'phase'), [
            0 => 'FirstInnings', 1 => 'SecondInnings', 2 => 'FirstHalf', 3 => 'SecondHalf', 4 => 'ExtraTime', 5 => 'PenaltyShootout',
        ]);
        if ($this->field($data, 'status') !== null && $status === null) {
            Http::problem('Status is not a supported match status.', 400);
        }
        if ($this->field($data, 'phase') !== null && $phase === null) {
            Http::problem('Phase is not a supported match phase.', 400);
        }
        if ($status !== null) {
            $allowed = [
                'Scheduled' => ['Live'],
                'Live' => ['Completed'],
            ];
            if (!in_array($status, $allowed[$fixture['Status']] ?? [], true)) {
                Http::problem("Cannot change fixture status from {$fixture['Status']} to {$status}.", 400);
            }
        }
        if ($phase !== null) {
            $validPhases = strcasecmp((string) $fixture['SportName'], 'Cricket') === 0
                ? ['FirstInnings', 'SecondInnings']
                : ['FirstHalf', 'SecondHalf', 'ExtraTime', 'PenaltyShootout'];
            if (!in_array($phase, $validPhases, true)) {
                Http::problem('Invalid match phase for the fixture sport.', 400);
            }
        }
        $this->db->execute('UPDATE Fixtures SET Status = :status, Phase = :phase, UpdatedAtUtc = SYSUTCDATETIME() WHERE Id = :id', [
            'status' => $status ?? $fixture['Status'],
            'phase' => $phase ?? $fixture['Phase'],
            'id' => $id,
        ]);
        Http::json($this->fixtureDto($this->fixtureRow($id) ?? []));
    }

    private function updateFixtureScore(string $routeId): never
    {
        $id = $this->uuid($routeId, 'fixtureId');
        $data = Http::body();
        $fixture = $this->fixtureRow($id);
        if ($fixture === null) {
            Http::problem('Fixture not found.', 404);
        }
        $side = $this->enumValue($this->field($data, 'side', ''), [0 => 'Home', 1 => 'Away']);
        if (!in_array($side, ['Home', 'Away'], true)) {
            Http::problem('Side must be Home or Away.', 400);
        }
        $phase = $fixture['Phase'];
        if (!in_array($phase, ['FirstInnings', 'SecondInnings'], true)) {
            Http::problem('Invalid innings phase for cricket score update.', 400);
        }
        $battingPlayerId = $this->uuid((string) $this->field($data, 'battingPlayerId', ''), 'battingPlayerId');
        $bowlingPlayerId = $this->uuid((string) $this->field($data, 'bowlingPlayerId', ''), 'bowlingPlayerId');
        $runs = filter_var($this->field($data, 'runsDelta'), FILTER_VALIDATE_INT);
        $overs = trim((string) $this->field($data, 'overs', ''));
        $wickets = $this->field($data, 'wicketsDelta');
        if ($runs === false || $runs < 0 || !preg_match('/^\d+(?:\.[0-5])?$/', $overs)) {
            Http::problem('RunsDelta must be a non-negative integer and Overs must be a valid cricket over value.', 400);
        }
        if ($wickets !== null && filter_var($wickets, FILTER_VALIDATE_INT) === false) {
            Http::problem('WicketsDelta must be an integer or null.', 400);
        }
        $teamId = $side === 'Home' ? (string) $fixture['HomeTeamId'] : (string) $fixture['AwayTeamId'];
        $opponentId = $side === 'Home' ? (string) $fixture['AwayTeamId'] : (string) $fixture['HomeTeamId'];
        $battingPlayer = $this->db->one('SELECT Id FROM Players WHERE Id = :id AND TeamId = :team', ['id' => $battingPlayerId, 'team' => $teamId]);
        $bowlingPlayer = $this->db->one('SELECT Id FROM Players WHERE Id = :id AND TeamId = :team', ['id' => $bowlingPlayerId, 'team' => $opponentId]);
        if ($battingPlayer === null || $bowlingPlayer === null) {
            Http::problem('Batting and bowling players must belong to their respective teams.', 400);
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $scoreColumn = $side === 'Home' ? 'HomeScore' : 'AwayScore';
            $wicketColumn = $side === 'Home' ? 'HomeWickets' : 'AwayWickets';
            $oversColumn = $side === 'Home' ? 'HomeScore_Overs' : 'AwayScore_Overs';
            $wicketDelta = $wickets === null ? 0 : (int) $wickets;
            $this->db->execute(
                "UPDATE Fixtures SET {$scoreColumn} = {$scoreColumn} + :runs, {$wicketColumn} = CASE WHEN :hasWickets = 1 THEN COALESCE({$wicketColumn}, 0) + :wicketDelta ELSE {$wicketColumn} END, {$oversColumn} = :overs, UpdatedAtUtc = SYSUTCDATETIME() WHERE Id = :id",
                ['runs' => $runs, 'hasWickets' => $wickets === null ? 0 : 1, 'wicketDelta' => $wicketDelta, 'overs' => $overs, 'id' => $id]
            );

            $innings = $phase === 'FirstInnings' ? 1 : 2;
            $scorecard = $this->db->one('SELECT Id FROM Scorecards WHERE FixtureId = :fixture AND InningsNo = :innings', ['fixture' => $id, 'innings' => $innings]);
            $scorecardId = $scorecard['Id'] ?? $this->newId();
            if ($scorecard === null) {
                $this->db->execute(
                    'INSERT INTO Scorecards (Id, FixtureId, InningsNo, BattingTeamId, BowlingTeamId) VALUES (:id, :fixture, :innings, :batting, :bowling)',
                    ['id' => $scorecardId, 'fixture' => $id, 'innings' => $innings, 'batting' => $teamId, 'bowling' => $opponentId]
                );
            }
            $battingFigure = $this->db->one('SELECT Id FROM BattingFigures WHERE ScorecardId = :scorecard AND PlayerId = :player', ['scorecard' => $scorecardId, 'player' => $battingPlayerId]);
            if ($battingFigure === null) {
                $this->db->execute('INSERT INTO BattingFigures (Id, ScorecardId, PlayerId, Runs, Balls, Fours, Sixes, StrikeRate) VALUES (:id, :scorecard, :player, 0, 0, 0, 0, 0)', [
                    'id' => $this->newId(), 'scorecard' => $scorecardId, 'player' => $battingPlayerId,
                ]);
            }
            $this->db->execute(
                'UPDATE BattingFigures SET Runs = Runs + :runs, Balls = Balls + 1, Fours = Fours + :four, Sixes = Sixes + :six, StrikeRate = CASE WHEN Balls + 1 = 0 THEN 0 ELSE CAST((Runs + :rateRuns) * 100.0 / (Balls + 1) AS decimal(6,2)) END WHERE ScorecardId = :scorecard AND PlayerId = :player',
                ['runs' => $runs, 'four' => $runs === 4 ? 1 : 0, 'six' => $runs === 6 ? 1 : 0, 'rateRuns' => $runs, 'scorecard' => $scorecardId, 'player' => $battingPlayerId]
            );
            $bowlingFigure = $this->db->one('SELECT Id FROM BowlingFigures WHERE ScorecardId = :scorecard AND PlayerId = :player', ['scorecard' => $scorecardId, 'player' => $bowlingPlayerId]);
            if ($bowlingFigure === null) {
                $this->db->execute('INSERT INTO BowlingFigures (Id, ScorecardId, PlayerId, Overs, Maidens, Runs, Wickets, NoBalls, Wides, Economy) VALUES (:id, :scorecard, :player, :overs, 0, 0, 0, 0, 0, 0)', [
                    'id' => $this->newId(), 'scorecard' => $scorecardId, 'player' => $bowlingPlayerId, 'overs' => '0',
                ]);
            }
            $overParts = array_map('intval', explode('.', $overs));
            $totalBalls = ($overParts[0] * 6) + ($overParts[1] ?? 0);
            $existingBowling = $this->db->one('SELECT Runs FROM BowlingFigures WHERE ScorecardId = :scorecard AND PlayerId = :player', ['scorecard' => $scorecardId, 'player' => $bowlingPlayerId]);
            $economy = $totalBalls > 0 ? round(((int) $existingBowling['Runs'] + $runs) / ($totalBalls / 6), 2) : 0;
            $this->db->execute('UPDATE BowlingFigures SET Overs = :overs, Runs = Runs + :runs, Economy = :economy WHERE ScorecardId = :scorecard AND PlayerId = :player', [
                'overs' => $overs, 'runs' => $runs, 'economy' => $economy, 'scorecard' => $scorecardId, 'player' => $bowlingPlayerId,
            ]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        $updatedFixture = $this->fixtureRow($id);
        if ($updatedFixture === null) {
            Http::problem('Fixture not found.', 404);
        }
        $this->broadcast('SCORE_BROADCAST_URL', [
            'fixtureId' => $id,
            'homeScore' => $updatedFixture['HomeScore'],
            'homeWickets' => $updatedFixture['HomeWickets'] ?? 0,
            'homeOvers' => $updatedFixture['HomeScore_Overs'],
            'awayScore' => $updatedFixture['AwayScore'],
            'awayWickets' => $updatedFixture['AwayWickets'] ?? 0,
            'awayOvers' => $updatedFixture['AwayScore_Overs'],
        ]);
        Http::json($this->fixtureDto($updatedFixture));
    }

    private function topPerformers(string $routeId): never
    {
        $id = $this->uuid($routeId, 'fixtureId');
        if ($this->fixtureRow($id) === null) {
            Http::problem('Fixture not found.', 404);
        }
        Http::json($this->performerRows($id));
    }

    private function performerRows(string $fixtureId): array
    {
        return $this->db->all(
            "SELECT TOP 4 p.Id AS playerId, p.Name AS playerName, t.Id AS teamId, t.Name AS teamName, SUM(CASE c.Action WHEN 'Six' THEN 6 WHEN 'Four' THEN 4 WHEN 'Single' THEN 1 WHEN 'Wide' THEN 1 ELSE 0 END) AS runsScored FROM CommentaryEntries c JOIN Players p ON p.Id = c.PlayerId JOIN Teams t ON t.Id = p.TeamId WHERE c.FixtureId = :fixture AND c.PlayerId IS NOT NULL GROUP BY p.Id, p.Name, t.Id, t.Name ORDER BY SUM(CASE c.Action WHEN 'Six' THEN 6 WHEN 'Four' THEN 4 WHEN 'Single' THEN 1 WHEN 'Wide' THEN 1 ELSE 0 END) DESC",
            ['fixture' => $fixtureId]
        );
    }

    private function scorecards(string $fixtureId): array
    {
        $cards = $this->db->all('SELECT Id, FixtureId, InningsNo, BattingTeamId, BowlingTeamId FROM Scorecards WHERE FixtureId = :id ORDER BY InningsNo', ['id' => $fixtureId]);
        foreach ($cards as &$card) {
            $card['battingFigures'] = $this->db->all(
                'SELECT b.Id AS id, b.PlayerId AS playerId, p.Name AS playerName, b.Runs AS runs, b.Balls AS balls, b.Fours AS fours, b.Sixes AS sixes, b.StrikeRate AS strikeRate FROM BattingFigures b JOIN Players p ON p.Id = b.PlayerId WHERE b.ScorecardId = :id ORDER BY b.Runs DESC',
                ['id' => $card['Id']]
            );
            $card['bowlingFigures'] = $this->db->all(
                'SELECT b.Id AS id, b.PlayerId AS playerId, p.Name AS playerName, b.Overs AS overs, b.Maidens AS maidens, b.Runs AS runs, b.Wickets AS wickets, b.NoBalls AS noBalls, b.Wides AS wides, b.Economy AS economy FROM BowlingFigures b JOIN Players p ON p.Id = b.PlayerId WHERE b.ScorecardId = :id ORDER BY b.Wickets DESC',
                ['id' => $card['Id']]
            );
            $card = [
                'id' => $card['Id'],
                'fixtureId' => $card['FixtureId'],
                'inningsNo' => $card['InningsNo'],
                'battingTeamId' => $card['BattingTeamId'],
                'bowlingTeamId' => $card['BowlingTeamId'],
                'battingFigures' => $card['battingFigures'],
                'bowlingFigures' => $card['bowlingFigures'],
            ];
        }
        return $cards;
    }

    private function updateCommentary(string $fixtureId, string $commentaryId): never
    {
        $id = $this->uuid($commentaryId, 'commentaryId');
        $data = Http::body();
        $note = $this->field($data, 'note');
        if ($note !== null && (!is_string($note) || mb_strlen($note) > 500)) {
            Http::problem('Note must be 500 characters or fewer.', 400);
        }
        $this->db->execute('UPDATE CommentaryEntries SET Note = :note, UpdatedAtUtc = SYSUTCDATETIME() WHERE Id = :id AND FixtureId = :fixture', [
            'note' => $note, 'id' => $id, 'fixture' => $fixtureId,
        ]);
        $row = $this->db->one('SELECT c.Id, c.FixtureId, c.Side, c.PlayerId, p.Name AS PlayerName, c.Action, c.Note, c.Ball, c.CreatedAtUtc, f.HomeScore, f.HomeWickets, f.AwayScore, f.AwayWickets, h.Name AS HomeName, a.Name AS AwayName, s.Name AS SportName FROM CommentaryEntries c JOIN Fixtures f ON f.Id = c.FixtureId JOIN Teams h ON h.Id = f.HomeTeamId JOIN Teams a ON a.Id = f.AwayTeamId JOIN Sports s ON s.Id = f.SportId LEFT JOIN Players p ON p.Id = c.PlayerId WHERE c.Id = :id AND c.FixtureId = :fixture', ['id' => $id, 'fixture' => $fixtureId]);
        if ($row === null) {
            Http::problem('Commentary entry not found.', 404);
        }
        Http::json($this->commentaryDto($row));
    }

    private function createCommentary(string $fixtureId): never
    {
        $data = Http::body();
        $fixture = $this->fixtureRow($fixtureId);
        if ($fixture === null) {
            Http::problem('Fixture not found.', 400);
        }
        if ($fixture['Status'] !== 'Live') {
            Http::problem('Commentary can only be added to a live fixture.', 400);
        }
        if (!in_array($fixture['SportName'], ['Cricket', 'Football'], true)) {
            Http::problem('Ball-by-ball commentary is only supported for cricket or football fixtures.', 400);
        }
        $side = $this->enumValue($this->field($data, 'side'), [0 => 'Home', 1 => 'Away']);
        $action = $this->enumValue($this->field($data, 'action'), [
            0 => 'Six', 1 => 'Four', 2 => 'Single', 3 => 'Wicket', 4 => 'Wide', 5 => 'Two',
            7 => 'Goal', 8 => 'Assist', 9 => 'Yellow_card', 10 => 'Red_Card', 11 => 'Substitution',
            12 => 'Penalty', 13 => 'Free_Kick', 14 => 'Corner', 15 => 'Offside', 16 => 'Save',
        ]);
        if (!in_array($side, ['Home', 'Away'], true) || !is_string($action)) {
            Http::problem('Side and Action are required.', 400);
        }
        $actions = ['Six', 'Four', 'Single', 'Wicket', 'Wide', 'Two', 'Goal', 'Assist', 'Yellow_card', 'Red_Card', 'Substitution', 'Penalty', 'Free_Kick', 'Corner', 'Offside', 'Save'];
        if (!in_array($action, $actions, true)) {
            Http::problem('Action is not a supported commentary value.', 400);
        }
        $playerId = $this->uuid((string) $this->field($data, 'playerId', ''), 'playerId');
        $teamId = $side === 'Home' ? $fixture['HomeTeamId'] : $fixture['AwayTeamId'];
        $player = $this->db->one('SELECT Id FROM Players WHERE Id = :player AND TeamId = :team', ['player' => $playerId, 'team' => $teamId]);
        if ($player === null) {
            Http::problem('Player does not belong to the team on the selected side.', 400);
        }
        $note = $this->field($data, 'note');
        $ball = $this->field($data, 'currentBall');
        if ($note !== null && (!is_string($note) || mb_strlen($note) > 500)) {
            Http::problem('Note must be 500 characters or fewer.', 400);
        }
        $id = $this->newId();
        $this->db->execute('INSERT INTO CommentaryEntries (Id, FixtureId, Side, PlayerId, Action, Note, Ball, CreatedAtUtc) VALUES (:id, :fixture, :side, :player, :action, :note, :ball, SYSUTCDATETIME())', [
            'id' => $id, 'fixture' => $fixtureId, 'side' => $side, 'player' => $playerId, 'action' => $action, 'note' => $note, 'ball' => $ball,
        ]);
        $row = $this->db->one('SELECT c.Id, c.FixtureId, c.Side, c.PlayerId, p.Name AS PlayerName, c.Action, c.Note, c.Ball, c.CreatedAtUtc, f.HomeScore, f.HomeWickets, f.AwayScore, f.AwayWickets, h.Name AS HomeName, a.Name AS AwayName, s.Name AS SportName FROM CommentaryEntries c JOIN Fixtures f ON f.Id = c.FixtureId JOIN Teams h ON h.Id = f.HomeTeamId JOIN Teams a ON a.Id = f.AwayTeamId JOIN Sports s ON s.Id = f.SportId LEFT JOIN Players p ON p.Id = c.PlayerId WHERE c.Id = :id', ['id' => $id]);
        $dto = $this->commentaryDto($row ?? []);
        $this->broadcast('COMMENTARY_BROADCAST_URL', $dto);
        Http::json($dto, 201);
    }

    private function getCommentary(string $fixtureId): never
    {
        if ($this->fixtureRow($fixtureId) === null) {
            Http::problem('Fixture not found.', 400);
        }
        Http::json($this->commentaryRows($fixtureId));
    }

    private function commentaryRows(string $fixtureId): array
    {
        $rows = $this->db->all(
            'SELECT c.Id, c.FixtureId, c.Side, c.PlayerId, p.Name AS PlayerName, c.Action, c.Note, c.Ball, c.CreatedAtUtc, f.HomeScore, f.HomeWickets, f.AwayScore, f.AwayWickets, h.Name AS HomeName, a.Name AS AwayName, s.Name AS SportName FROM CommentaryEntries c JOIN Fixtures f ON f.Id = c.FixtureId JOIN Teams h ON h.Id = f.HomeTeamId JOIN Teams a ON a.Id = f.AwayTeamId JOIN Sports s ON s.Id = f.SportId LEFT JOIN Players p ON p.Id = c.PlayerId WHERE c.FixtureId = :fixture ORDER BY c.CreatedAtUtc DESC',
            ['fixture' => $fixtureId]
        );
        return array_map(fn (array $row): array => $this->commentaryDto($row), $rows);
    }

    private function commentaryDto(array $row): array
    {
        return [
            'id' => $row['Id'],
            'fixtureId' => $row['FixtureId'],
            'side' => $row['Side'],
            'playerId' => $row['PlayerId'] ?? '00000000-0000-0000-0000-000000000000',
            'playerName' => $row['PlayerName'] ?? '',
            'action' => $row['Action'],
            'note' => $row['Note'],
            'ball' => $row['Ball'],
            'createdAtUtc' => $row['CreatedAtUtc'],
            'homeScore' => $row['HomeScore'],
            'homeWickets' => $row['HomeWickets'],
            'awayScore' => $row['AwayScore'],
            'awayWickets' => $row['AwayWickets'],
            'fixtureName' => trim(($row['HomeName'] ?? '') . ' v ' . ($row['AwayName'] ?? '')),
            'sport' => $row['SportName'],
        ];
    }

    private function fixtureRow(string $id): ?array
    {
        return $this->db->one(
            'SELECT f.*, h.Name AS HomeTeamName, a.Name AS AwayTeamName, s.Name AS SportName FROM Fixtures f JOIN Teams h ON h.Id = f.HomeTeamId JOIN Teams a ON a.Id = f.AwayTeamId JOIN Sports s ON s.Id = f.SportId WHERE f.Id = :id',
            ['id' => $id]
        );
    }

    private function fixtureDto(array $row): array
    {
        return [
            'id' => $row['Id'],
            'homeTeamId' => $row['HomeTeamId'],
            'homeTeamName' => $row['HomeTeamName'],
            'awayTeamId' => $row['AwayTeamId'],
            'awayTeamName' => $row['AwayTeamName'],
            'sport' => $row['SportName'],
            'scheduledAtUtc' => $row['ScheduledAtUtc'],
            'status' => $row['Status'],
            'phase' => $row['Phase'],
            'homeScore' => $row['HomeScore'],
            'homeWickets' => $row['HomeWickets'],
            'homeOvers' => $row['HomeScore_Overs'],
            'awayScore' => $row['AwayScore'],
            'awayWickets' => $row['AwayWickets'],
            'awayOvers' => $row['AwayScore_Overs'],
            'totalOvers' => $row['TotalOvers'],
            'sportId' => $row['SportId'],
            'scorecards' => $this->scorecards((string) $row['Id']),
        ];
    }

    private function external(string $service, string $endpoint, array $query = []): never
    {
        $base = $service === 'cricapi'
            ? (getenv('CRICAPI_BASE_URL') ?: 'https://api.cricapi.com/v1/')
            : (getenv('CRICBUZZ_BASE_URL') ?: 'https://www.cricbuzz.com/');
        $url = rtrim($base, '/') . '/' . ltrim($endpoint, '/');
        if ($service === 'cricapi') {
            $key = getenv('CRICAPI_KEY') ?: '';
            if ($key === '') {
                Http::problem('CRICAPI_KEY is not configured.', 503);
            }
            $query['apikey'] = $key;
        }
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }
        try {
            Http::json($this->fetchJson($url));
        } catch (\RuntimeException) {
            Http::problem('External cricket data service returned an error.', 502);
        }
    }

    private function searchCricbuzzMatches(string $searchText): never
    {
        $base = rtrim(getenv('CRICBUZZ_BASE_URL') ?: 'https://www.cricbuzz.com/', '/');
        try {
            $response = $this->fetchJson($base . '/api/home');
        } catch (\RuntimeException) {
            Http::problem('External cricket data service returned an error.', 502);
        }
        if (trim($searchText) !== '') {
            $matchesKey = $this->key($response, 'matches');
            $matches = $matchesKey === null ? null : $response[$matchesKey];
            if (is_array($matches)) {
                $response[$matchesKey] = array_values(array_filter($matches, function (mixed $match) use ($searchText): bool {
                    if (!is_array($match)) {
                        return false;
                    }
                    $matchData = $this->value($match, 'match') ?? $match;
                    $info = is_array($matchData) ? ($this->value($matchData, 'matchInfo') ?? []) : [];
                    $team1 = is_array($info) ? ($this->value($info, 'team1') ?? []) : [];
                    $team2 = is_array($info) ? ($this->value($info, 'team2') ?? []) : [];
                    $fields = [
                        is_array($team1) ? $this->value($team1, 'teamName') : null,
                        is_array($team2) ? $this->value($team2, 'teamName') : null,
                        $this->value($info, 'seriesName'),
                        $this->value($info, 'matchDesc'),
                    ];
                    foreach ($fields as $field) {
                        if (is_string($field) && stripos($field, $searchText) !== false) {
                            return true;
                        }
                    }
                    return false;
                }));
            }
        }
        Http::json($response);
    }

    private function cricbuzzCommentary(int $matchId): never
    {
        $base = rtrim(getenv('CRICBUZZ_BASE_URL') ?: 'https://www.cricbuzz.com/', '/');
        $innings = [];
        $seen = [];
        for ($inningsId = 1; $inningsId <= 4; $inningsId++) {
            try {
                $response = $this->fetchJson($base . "/api/mcenter/{$matchId}/full-commentary/{$inningsId}");
            } catch (\RuntimeException) {
                continue;
            }
            $commentaryKey = $this->key($response, 'commentary');
            $commentary = $commentaryKey === null ? [] : $response[$commentaryKey];
            if (!is_array($commentary)) {
                continue;
            }
            foreach ($commentary as $inning) {
                if (!is_array($inning)) {
                    continue;
                }
                $items = [];
                $list = $this->value($inning, 'commentaryList') ?? [];
                if (!is_array($list)) {
                    continue;
                }
                foreach ($list as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $cleanText = (string) ($this->value($item, 'commText') ?? '');
                    $cleanText = preg_replace('/[BI]\d+\$/', '', $cleanText) ?? $cleanText;
                    $cleanText = str_replace('\\n', PHP_EOL, $cleanText);
                    $text = trim(preg_replace('/[ \t]+/', ' ', $cleanText) ?? '');
                    $text = trim(preg_replace('/(\r?\n){3,}/', PHP_EOL . PHP_EOL, $text) ?? $text);
                    if ($text === '') {
                        continue;
                    }
                    $commentaryItem = [
                        'overNumber' => $this->value($item, 'overNumber'),
                        'ballNumber' => $this->value($item, 'ballNbr'),
                        'inningsId' => $this->value($item, 'inningsId') ?? $inningsId,
                        'commentary' => $text,
                        'event' => $this->value($item, 'event'),
                        'battingTeam' => $this->value($item, 'batTeamName'),
                        'score' => $this->value($item, 'batTeamScore'),
                        'batsmanName' => $this->value($this->value($item, 'batsmanStriker') ?? [], 'batName'),
                        'bowlerName' => $this->value($this->value($item, 'bowlerStriker') ?? [], 'bowlName'),
                        'timestamp' => $this->value($item, 'timestamp'),
                    ];
                    $key = json_encode([
                        $commentaryItem['inningsId'],
                        $commentaryItem['ballNumber'],
                        $commentaryItem['timestamp'],
                        $commentaryItem['commentary'],
                    ], JSON_THROW_ON_ERROR);
                    if (!isset($seen[$key])) {
                        $seen[$key] = true;
                        $items[] = $commentaryItem;
                    }
                }
                if ($items !== []) {
                    usort($items, static fn (array $a, array $b): int => (int) ($b['timestamp'] ?? 0) <=> (int) ($a['timestamp'] ?? 0));
                    $innings[] = ['inningsId' => $inningsId, 'commentary' => $items];
                }
            }
        }
        usort($innings, static fn (array $a, array $b): int => $b['inningsId'] <=> $a['inningsId']);
        Http::json(['matchId' => $matchId, 'innings' => $innings]);
    }

    private function fetchJson(string $url): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 15,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\nUser-Agent: MatchApi-PHP/1.0\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new \RuntimeException('External cricket data service could not be reached.');
        }
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) {
                $status = (int) $match[1];
            }
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('External cricket data service returned an error.');
        }
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('External cricket data service returned invalid JSON.');
        }
        if (!is_array($data)) {
            throw new \RuntimeException('External cricket data service returned an unexpected response.');
        }
        return $data;
    }

    private function broadcast(string $setting, array $payload): void
    {
        $url = getenv($setting) ?: '';
        if ($url === '') {
            return;
        }
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => 5,
                'ignore_errors' => true,
                'header' => "Content-Type: application/json\r\n",
                'content' => json_encode($payload, JSON_THROW_ON_ERROR),
            ],
        ]);
        $result = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) {
                $status = (int) $match[1];
            }
        }
        if ($result === false || $status < 200 || $status >= 300) {
            throw new \RuntimeException('Configured realtime broadcast endpoint could not be reached.');
        }
    }

    private function field(array $data, string $name, mixed $default = null): mixed
    {
        foreach ($data as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return $value;
            }
        }
        return $default;
    }

    private function key(array $data, string $name): ?string
    {
        foreach (array_keys($data) as $key) {
            if (strcasecmp((string) $key, $name) === 0) {
                return (string) $key;
            }
        }
        return null;
    }

    private function value(mixed $data, string $name): mixed
    {
        if (!is_array($data)) {
            return null;
        }
        $key = $this->key($data, $name);
        return $key === null ? null : $data[$key];
    }

    private function enumValue(mixed $value, array $values): ?string
    {
        if (is_int($value) && array_key_exists($value, $values)) {
            return $values[$value];
        }
        if (!is_string($value)) {
            return null;
        }
        foreach ($values as $name) {
            if (strcasecmp($name, $value) === 0) {
                return $name;
            }
        }
        return null;
    }

    private function uuid(string $value, string $field): string
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
            Http::json(['errors' => [$field => ['A valid UUID is required.']]], 400);
        }
        return strtolower($value);
    }

    private function newId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }

    private function openApiDocument(): array
    {
        $paths = [
            '/api/admin/register' => ['post'],
            '/api/admin/login' => ['post'],
            '/api/admin/approval-requests' => ['get'],
            '/api/admin/approve/{id}' => ['put'],
            '/api/admin/all' => ['get'],
            '/api/fixtures' => ['post'],
            '/api/fixtures/all' => ['get'],
            '/api/fixtures/live' => ['get'],
            '/api/fixtures/search' => ['get'],
            '/api/fixtures/{fixtureId}' => ['get', 'patch'],
            '/api/fixtures/{fixtureId}/score' => ['patch'],
            '/api/fixtures/{fixtureId}/top-performers' => ['get'],
            '/api/fixtures/{fixtureId}/commentary' => ['get', 'post'],
            '/api/fixtures/{fixtureId}/commentary/{commentaryId}' => ['patch'],
            '/api/teams' => ['get', 'post'],
            '/api/teams/{id}' => ['put', 'delete'],
            '/api/players' => ['post'],
            '/api/players/team/{teamId}' => ['get'],
            '/api/players/{id}' => ['put', 'delete'],
            '/api/sports' => ['get', 'post'],
            '/api/sports/roles' => ['get', 'post'],
            '/api/sports/{sportId}/roles' => ['get'],
            '/api/matches/current' => ['get'],
            '/api/matches/{matchId}' => ['get'],
            '/api/matches/search' => ['get'],
            '/api/matches/cricbuzz/current' => ['get'],
            '/api/matches/cricbuzz/{matchId}/info' => ['get'],
            '/api/matches/cricbuzz/{matchId}/scorecard' => ['get'],
            '/api/matches/{cricbuzzMatchId}/commentary' => ['get'],
        ];
        $documentPaths = [];
        foreach ($paths as $path => $methods) {
            foreach ($methods as $method) {
                $documentPaths[$path][$method] = [
                    'responses' => ['200' => ['description' => 'Successful response']],
                ];
            }
        }
        return [
            'openapi' => '3.0.3',
            'info' => ['title' => 'MatchApi PHP', 'version' => '1.0.0'],
            'paths' => $documentPaths,
        ];
    }
}
