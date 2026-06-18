-- Expand genre_catalog (Ian 6/15: "juice up the music category — want for nothing
-- within reason"). Music = musical GENRES/styles only (instruments + skills are
-- their own catalogs). Grouped by family; sort_orders interleave into the gaps
-- between the existing anchors so the chip picker reads family-by-family.
-- ON CONFLICT (slug) DO NOTHING — existing rows untouched; safe to re-run at cut.

INSERT INTO genre_catalog (slug, name, sort_order) VALUES
  -- Jazz (anchor 10; gypsy-jazz 20)
  ('swing','Swing',12), ('bebop','Bebop',13), ('jazz-fusion','Jazz fusion',14),
  ('latin-jazz','Latin jazz',16), ('smooth-jazz','Smooth jazz',18),
  -- Blues (anchor 30)
  ('delta-blues','Delta blues',31), ('chicago-blues','Chicago blues',32),
  ('texas-blues','Texas blues',33), ('electric-blues','Electric blues',34),
  ('rhythm-and-blues','Rhythm & blues',36),
  -- Rock (anchor 40)
  ('rock-and-roll','Rock & roll',41), ('classic-rock','Classic rock',42),
  ('hard-rock','Hard rock',43), ('progressive-rock','Progressive rock',44),
  ('psychedelic-rock','Psychedelic rock',45), ('alternative-rock','Alternative rock',46),
  ('grunge','Grunge',47), ('garage-rock','Garage rock',48), ('surf-rock','Surf rock',49),
  -- Southern / roots-rock & Pop (folk anchor 50, americana 50)
  ('southern-rock','Southern rock',51), ('roots-rock','Roots rock',52),
  ('pop','Pop',53), ('pop-rock','Pop rock',54), ('power-pop','Power pop',55),
  ('folk-rock','Folk rock',56), ('contemporary-folk','Contemporary folk',57),
  ('old-time','Old-time',58),
  -- Country (anchor 70; bluegrass 60)
  ('honky-tonk','Honky-tonk',71), ('outlaw-country','Outlaw country',72),
  ('country-rock','Country rock',73), ('western-swing','Western swing',74),
  ('rockabilly','Rockabilly',75), ('alt-country','Alt-country',76),
  -- Classical (anchor 80) & Latin (flamenco 90)
  ('baroque','Baroque',81), ('contemporary-classical','Contemporary classical',83),
  ('latin','Latin',91), ('bossa-nova','Bossa nova',92), ('samba','Samba',93),
  -- Metal (anchor 120)
  ('heavy-metal','Heavy metal',121), ('thrash-metal','Thrash metal',122),
  ('death-metal','Death metal',123), ('doom-metal','Doom metal',124),
  ('progressive-metal','Progressive metal',125), ('metalcore','Metalcore',126),
  ('power-metal','Power metal',127), ('nu-metal','Nu-metal',128),
  -- Punk (anchor 130) + ska
  ('pop-punk','Pop-punk',131), ('hardcore-punk','Hardcore punk',132),
  ('post-punk','Post-punk',133), ('emo','Emo',134), ('ska','Ska',135),
  -- Funk / Soul / R&B (funk 140, soul 150)
  ('disco','Disco',141), ('motown','Motown',142), ('neo-soul','Neo-soul',151),
  ('gospel','Gospel',152),
  -- Reggae (anchor 160)
  ('dub','Dub',161), ('dancehall','Dancehall',162),
  -- Indie (anchor 190)
  ('indie-rock','Indie rock',191), ('shoegaze','Shoegaze',192),
  ('post-rock','Post-rock',193), ('math-rock','Math rock',194), ('dream-pop','Dream pop',195),
  -- Electronic / Ambient (ambient 200) & World (180)
  ('electronic','Electronic',201), ('lo-fi','Lo-fi',202), ('synthwave','Synthwave',203),
  ('experimental','Experimental',204), ('afrobeat','Afrobeat',185)
ON CONFLICT (slug) DO NOTHING;

-- Normalize two ad-hoc rows that came in lowercase (cosmetic only).
UPDATE genre_catalog SET name = 'Americana' WHERE slug = 'americana' AND name = 'americana';
UPDATE genre_catalog SET name = 'Hip-hop'   WHERE slug = 'hip-hop'   AND name = 'hip hop';
