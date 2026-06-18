-- Expand instrument_catalog: fretted families + the violin/viol (bowed) family.
-- Sourced from Wikipedia "List of string instruments" / "Violin family" + Hobgoblin/Lark.
-- ON CONFLICT (slug) DO NOTHING so existing rows are untouched.

INSERT INTO instrument_catalog (slug, name, type, subtype, sort_order) VALUES
  -- guitar family
  ('baritone-guitar','Baritone guitar','guitar','baritone',12),
  ('tenor-guitar','Tenor guitar','guitar','tenor',14),
  ('parlor-guitar','Parlor guitar','guitar','acoustic',16),
  ('flamenco-guitar','Flamenco guitar','guitar','classical',18),
  ('harp-guitar','Harp guitar','guitar','harp',20),
  ('requinto-guitar','Requinto','guitar','classical',22),
  ('seven-string-guitar','7-string guitar','guitar','electric',24),
  ('eight-string-guitar','8-string guitar','guitar','electric',26),
  ('nylon-crossover','Nylon crossover / electro-classical','guitar','classical',28),
  -- bass family (fretted + variants)
  ('acoustic-bass-guitar','Acoustic bass guitar','bass','acoustic',52),
  ('fretless-bass','Fretless bass','bass','electric',54),
  ('five-string-bass','5-string bass','bass','electric',56),
  ('six-string-bass','6-string bass','bass','electric',58),
  -- mandolin family
  ('octave-mandolin','Octave mandolin','mando','',82),
  ('mandocello','Mandocello','mando','',84),
  ('mandobass','Mandobass','mando','',86),
  ('piccolo-mandolin','Piccolo mandolin','mando','',88),
  ('irish-bouzouki','Irish bouzouki','mando','',90),
  ('greek-bouzouki','Greek bouzouki','mando','',92),
  ('cittern','Cittern','mando','',94),
  -- banjo family
  ('banjo-6','6-string banjo (banjitar)','banjo','6-string',122),
  ('banjolele','Banjo ukulele (banjolele)','banjo','',124),
  ('banjo-bass','Bass banjo','banjo','',126),
  -- ukulele family (by size)
  ('soprano-ukulele','Soprano ukulele','ukulele','',142),
  ('concert-ukulele','Concert ukulele','ukulele','',144),
  ('tenor-ukulele','Tenor ukulele','ukulele','',146),
  ('baritone-ukulele','Baritone ukulele','ukulele','',148),
  ('bass-ukulele','Bass ukulele','ukulele','',150),
  -- world fretted / plucked lutes
  ('lute','Lute','world','',202),
  ('oud','Oud','world','',204),
  ('balalaika','Balalaika','world','',206),
  ('domra','Domra','world','',208),
  ('saz-baglama','Saz / bağlama','world','',210),
  ('charango','Charango','world','',212),
  ('cuatro','Cuatro','world','',214),
  ('tres','Tres (Cuban)','world','',216),
  ('bajo-sexto','Bajo sexto','world','',218),
  ('vihuela','Vihuela','world','',220),
  ('cavaquinho','Cavaquinho','world','',222),
  ('tiple','Tiple','world','',224),
  ('bandurria','Bandurria','world','',226),
  ('laud','Laúd','world','',228),
  ('sitar','Sitar','world','',230),
  ('veena','Veena','world','',232),
  ('pipa','Pipa','world','',234),
  ('setar','Setar','world','',236),
  ('tanbur','Tanbur','world','',238),
  -- violin / viol (bowed) family
  ('double-bass','Double bass','bowed','',302),
  ('violin-5string','5-string fiddle / violin','bowed','violin',304),
  ('baroque-violin','Baroque violin','bowed','violin',306),
  ('viola-damore','Viola d''amore','bowed','',308),
  ('treble-viol','Treble viol','bowed','viol',310),
  ('tenor-viol','Tenor viol','bowed','viol',312),
  ('bass-viol','Bass viol (viola da gamba)','bowed','viol',314),
  ('violone','Violone','bowed','viol',316),
  ('hardanger-fiddle','Hardanger fiddle','bowed','violin',318),
  ('nyckelharpa','Nyckelharpa','bowed','',320),
  ('erhu','Erhu','bowed','world',322)
ON CONFLICT (slug) DO NOTHING;

-- Central & South America supplement
INSERT INTO instrument_catalog (slug, name, type, subtype, sort_order) VALUES
  ('ronroco','Ronroco','world','latin',240),
  ('walaycho','Walaycho / hualaycho','world','latin',241),
  ('viola-caipira','Viola caipira','world','latin',242),
  ('guitarron','Guitarrón (mariachi)','world','latin',243),
  ('guitarron-chileno','Guitarrón chileno','world','latin',244),
  ('vihuela-mexicana','Vihuela (Mexican mariachi)','world','latin',245),
  ('guitarra-de-golpe','Guitarra de golpe','world','latin',246),
  ('jarana-jarocha','Jarana jarocha','world','latin',247),
  ('jarana-huasteca','Jarana huasteca','world','latin',248),
  ('requinto-jarocho','Requinto jarocho','world','latin',249),
  ('bajo-quinto','Bajo quinto','world','latin',250),
  ('bandola-llanera','Bandola llanera','world','latin',251),
  ('bandola-andina','Bandola andina','world','latin',252),
  ('cuatro-venezolano','Cuatro venezolano','world','latin',253),
  ('cuatro-puertorriqueno','Cuatro puertorriqueño','world','latin',254),
  ('tiple-colombiano','Tiple colombiano','world','latin',255),
  ('charango-bajo','Charango bajo','world','latin',256),
  ('berimbau','Berimbau (musical bow)','world','latin',257),
  ('cavaquinho-brasileiro','Cavaquinho (Brazilian)','world','latin',258),
  ('bandolim','Bandolim (Brazilian mandolin)','world','latin',259)
ON CONFLICT (slug) DO NOTHING;
