CREATE TABLE Sports (
    Id uniqueidentifier NOT NULL CONSTRAINT PK_Sports PRIMARY KEY,
    Name nvarchar(50) NOT NULL,
    Description nvarchar(200) NOT NULL,
    CreatedAtUtc datetime2 NOT NULL,
    UpdatedAtUtc datetime2 NULL,
    CONSTRAINT UQ_Sports_Name UNIQUE (Name)
);

CREATE TABLE SportRoles (
    Id uniqueidentifier NOT NULL CONSTRAINT PK_SportRoles PRIMARY KEY,
    SportId uniqueidentifier NOT NULL,
    RoleName nvarchar(50) NOT NULL,
    Description nvarchar(250) NOT NULL,
    CreatedAtUtc datetime2 NOT NULL,
    UpdatedAtUtc datetime2 NULL,
    CONSTRAINT FK_SportRoles_Sports FOREIGN KEY (SportId) REFERENCES Sports (Id) ON DELETE CASCADE,
    CONSTRAINT UQ_SportRoles_Sport_Role UNIQUE (SportId, RoleName)
);

CREATE TABLE Teams (
    Id uniqueidentifier NOT NULL CONSTRAINT PK_Teams PRIMARY KEY,
    Name nvarchar(100) NOT NULL,
    SportId uniqueidentifier NOT NULL,
    ColorHex nvarchar(20) NOT NULL,
    CreatedAtUtc datetime2 NOT NULL,
    UpdatedAtUtc datetime2 NULL,
    CONSTRAINT FK_Teams_Sports FOREIGN KEY (SportId) REFERENCES Sports (Id)
);

CREATE TABLE Players (
    Id uniqueidentifier NOT NULL CONSTRAINT PK_Players PRIMARY KEY,
    Name nvarchar(100) NOT NULL,
    TeamId uniqueidentifier NOT NULL,
    SportRoleId uniqueidentifier NOT NULL,
    CreatedAtUtc datetime2 NOT NULL,
    UpdatedAtUtc datetime2 NULL,
    CONSTRAINT FK_Players_Teams FOREIGN KEY (TeamId) REFERENCES Teams (Id) ON DELETE CASCADE,
    CONSTRAINT FK_Players_SportRoles FOREIGN KEY (SportRoleId) REFERENCES SportRoles (Id)
);

CREATE TABLE Fixtures (
    Id uniqueidentifier NOT NULL CONSTRAINT PK_Fixtures PRIMARY KEY,
    HomeTeamId uniqueidentifier NOT NULL,
    AwayTeamId uniqueidentifier NOT NULL,
    SportId uniqueidentifier NOT NULL,
    ScheduledAtUtc datetime2 NOT NULL,
    Status nvarchar(20) NOT NULL,
    Phase nvarchar(20) NULL,
    HomeScore int NOT NULL CONSTRAINT DF_Fixtures_HomeScore DEFAULT (0),
    HomeWickets int NULL,
    HomeScore_Overs nvarchar(max) NOT NULL CONSTRAINT DF_Fixtures_HomeOvers DEFAULT ('0.0'),
    AwayScore int NOT NULL CONSTRAINT DF_Fixtures_AwayScore DEFAULT (0),
    AwayWickets int NULL,
    AwayScore_Overs nvarchar(max) NOT NULL CONSTRAINT DF_Fixtures_AwayOvers DEFAULT ('0.0'),
    TotalOvers nvarchar(max) NULL,
    CreatedAtUtc datetime2 NOT NULL,
    UpdatedAtUtc datetime2 NULL,
    CONSTRAINT FK_Fixtures_HomeTeam FOREIGN KEY (HomeTeamId) REFERENCES Teams (Id),
    CONSTRAINT FK_Fixtures_AwayTeam FOREIGN KEY (AwayTeamId) REFERENCES Teams (Id),
    CONSTRAINT FK_Fixtures_Sports FOREIGN KEY (SportId) REFERENCES Sports (Id)
);

CREATE TABLE CommentaryEntries (
    Id uniqueidentifier NOT NULL CONSTRAINT PK_CommentaryEntries PRIMARY KEY,
    FixtureId uniqueidentifier NOT NULL,
    Side nvarchar(20) NOT NULL,
    PlayerId uniqueidentifier NULL,
    Action nvarchar(30) NOT NULL,
    Note nvarchar(500) NULL,
    Ball nvarchar(max) NULL,
    CreatedAtUtc datetime2 NOT NULL,
    UpdatedAtUtc datetime2 NULL,
    CONSTRAINT FK_CommentaryEntries_Fixtures FOREIGN KEY (FixtureId) REFERENCES Fixtures (Id) ON DELETE CASCADE,
    CONSTRAINT FK_CommentaryEntries_Players FOREIGN KEY (PlayerId) REFERENCES Players (Id)
);

CREATE TABLE Scorecards (
    Id uniqueidentifier NOT NULL CONSTRAINT PK_Scorecards PRIMARY KEY,
    FixtureId uniqueidentifier NOT NULL,
    InningsNo int NOT NULL,
    BattingTeamId uniqueidentifier NOT NULL,
    BowlingTeamId uniqueidentifier NOT NULL,
    CONSTRAINT FK_Scorecards_Fixtures FOREIGN KEY (FixtureId) REFERENCES Fixtures (Id) ON DELETE CASCADE,
    CONSTRAINT FK_Scorecards_BattingTeams FOREIGN KEY (BattingTeamId) REFERENCES Teams (Id),
    CONSTRAINT FK_Scorecards_BowlingTeams FOREIGN KEY (BowlingTeamId) REFERENCES Teams (Id),
    CONSTRAINT UQ_Scorecards_Fixture_Innings UNIQUE (FixtureId, InningsNo)
);

CREATE TABLE BattingFigures (
    Id uniqueidentifier NOT NULL CONSTRAINT PK_BattingFigures PRIMARY KEY,
    ScorecardId uniqueidentifier NOT NULL,
    PlayerId uniqueidentifier NOT NULL,
    Runs int NOT NULL,
    Balls int NOT NULL,
    Fours int NOT NULL,
    Sixes int NOT NULL,
    StrikeRate decimal(6,2) NOT NULL,
    CONSTRAINT FK_BattingFigures_Scorecards FOREIGN KEY (ScorecardId) REFERENCES Scorecards (Id) ON DELETE CASCADE,
    CONSTRAINT FK_BattingFigures_Players FOREIGN KEY (PlayerId) REFERENCES Players (Id),
    CONSTRAINT UQ_BattingFigures_Scorecard_Player UNIQUE (ScorecardId, PlayerId)
);

CREATE TABLE BowlingFigures (
    Id uniqueidentifier NOT NULL CONSTRAINT PK_BowlingFigures PRIMARY KEY,
    ScorecardId uniqueidentifier NOT NULL,
    PlayerId uniqueidentifier NOT NULL,
    Overs nvarchar(10) NOT NULL,
    Maidens int NOT NULL,
    Runs int NOT NULL,
    Wickets int NOT NULL,
    NoBalls int NOT NULL,
    Wides int NOT NULL,
    Economy decimal(6,2) NOT NULL,
    CONSTRAINT FK_BowlingFigures_Scorecards FOREIGN KEY (ScorecardId) REFERENCES Scorecards (Id) ON DELETE CASCADE,
    CONSTRAINT FK_BowlingFigures_Players FOREIGN KEY (PlayerId) REFERENCES Players (Id),
    CONSTRAINT UQ_BowlingFigures_Scorecard_Player UNIQUE (ScorecardId, PlayerId)
);

CREATE TABLE AdminUsers (
    Id uniqueidentifier NOT NULL CONSTRAINT PK_AdminUsers PRIMARY KEY,
    FirstName nvarchar(100) NOT NULL,
    LastName nvarchar(100) NOT NULL,
    Email nvarchar(256) NOT NULL,
    PasswordHash nvarchar(512) NOT NULL,
    PasswordSalt nvarchar(512) NOT NULL,
    IsApproved bit NOT NULL CONSTRAINT DF_AdminUsers_IsApproved DEFAULT (0),
    IsSuperAdmin bit NOT NULL CONSTRAINT DF_AdminUsers_IsSuperAdmin DEFAULT (0),
    CreatedAtUtc datetime2 NOT NULL,
    UpdatedAtUtc datetime2 NULL,
    CONSTRAINT UQ_AdminUsers_Email UNIQUE (Email)
);

INSERT INTO Sports (Id, Name, Description, CreatedAtUtc)
SELECT '11111111-1111-1111-1111-111111111111', 'Cricket', 'Description', '2026-07-01'
WHERE NOT EXISTS (SELECT 1 FROM Sports WHERE Id = '11111111-1111-1111-1111-111111111111');

INSERT INTO Sports (Id, Name, Description, CreatedAtUtc)
SELECT '22222222-2222-2222-2222-222222222222', 'Football', 'Description', '2026-07-01'
WHERE NOT EXISTS (SELECT 1 FROM Sports WHERE Id = '22222222-2222-2222-2222-222222222222');

INSERT INTO SportRoles (Id, SportId, RoleName, Description, CreatedAtUtc)
SELECT seed.Id, seed.SportId, seed.RoleName, 'Description', '2026-07-01'
FROM (VALUES
    ('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', '11111111-1111-1111-1111-111111111111', 'Batter'),
    ('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', '11111111-1111-1111-1111-111111111111', 'Bowler'),
    ('cccccccc-cccc-cccc-cccc-cccccccccccc', '11111111-1111-1111-1111-111111111111', 'All-rounder'),
    ('dddddddd-dddd-dddd-dddd-dddddddddddd', '22222222-2222-2222-2222-222222222222', 'Goalkeeper'),
    ('eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee', '22222222-2222-2222-2222-222222222222', 'Defender'),
    ('ffffffff-ffff-ffff-ffff-ffffffffffff', '22222222-2222-2222-2222-222222222222', 'Forward'),
    ('12121212-1212-1212-1212-121212121212', '22222222-2222-2222-2222-222222222222', 'Midfielder')
) AS seed (Id, SportId, RoleName)
WHERE NOT EXISTS (SELECT 1 FROM SportRoles role WHERE role.Id = seed.Id);

INSERT INTO Teams (Id, Name, SportId, ColorHex, CreatedAtUtc)
SELECT seed.Id, seed.Name, seed.SportId, seed.ColorHex, '2026-07-01'
FROM (VALUES
    ('11111111-1111-1111-1111-111111111111', 'NVian Strikers', '11111111-1111-1111-1111-111111111111', '#8B5CF6'),
    ('22222222-2222-2222-2222-222222222222', 'NVian FC', '22222222-2222-2222-2222-222222222222', '#3B82F6'),
    ('55555555-5555-5555-5555-555555555555', 'Hyderabad', '11111111-1111-1111-1111-111111111111', '#F97316')
) AS seed (Id, Name, SportId, ColorHex)
WHERE NOT EXISTS (SELECT 1 FROM Teams team WHERE team.Id = seed.Id);

INSERT INTO Players (Id, Name, TeamId, SportRoleId, CreatedAtUtc)
SELECT seed.Id, seed.Name, seed.TeamId, seed.SportRoleId, '2026-07-01'
FROM (VALUES
    ('33333333-3333-3333-3333-333333333331', 'Rohit Sharma', '11111111-1111-1111-1111-111111111111', 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'),
    ('33333333-3333-3333-3333-333333333332', 'Jasprit B', '11111111-1111-1111-1111-111111111111', 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'),
    ('33333333-3333-3333-3333-333333333333', 'Hardik P', '11111111-1111-1111-1111-111111111111', 'cccccccc-cccc-cccc-cccc-cccccccccccc'),
    ('44444444-4444-4444-4444-444444444441', 'Diallo', '22222222-2222-2222-2222-222222222222', 'ffffffff-ffff-ffff-ffff-ffffffffffff'),
    ('44444444-4444-4444-4444-444444444442', 'Okafor', '22222222-2222-2222-2222-222222222222', '12121212-1212-1212-1212-121212121212'),
    ('55555555-5555-5555-5555-555555555551', 'Abhishek S', '22222222-2222-2222-2222-222222222222', 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'),
    ('55555555-5555-5555-5555-555555555552', 'Bhuvneshwar K', '55555555-5555-5555-5555-555555555555', 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'),
    ('55555555-5555-5555-5555-555555555553', 'Nitish R', '55555555-5555-5555-5555-555555555555', 'cccccccc-cccc-cccc-cccc-cccccccccccc')
) AS seed (Id, Name, TeamId, SportRoleId)
WHERE NOT EXISTS (SELECT 1 FROM Players player WHERE player.Id = seed.Id);
