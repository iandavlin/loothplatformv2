-- profile-app slice 2 catalog seeds.
-- These are the curated starting lists. Free-text fallback exists only for
-- credentials. Instruments / skills / scenes are pick-from-list.
--
-- Counts: 25 instruments, 50 skills, 60 credentials, 14 scenes.

BEGIN;

-- ----- instruments (25) -------------------------------------------------
INSERT INTO instrument_catalog (slug, name, type, subtype, sort_order) VALUES
('acoustic-flattop',  'Acoustic guitar (flat-top)', 'guitar', 'acoustic', 10),
('acoustic-archtop',  'Acoustic guitar (archtop)',  'guitar', 'acoustic', 11),
('classical-guitar',  'Classical guitar',           'guitar', 'classical',12),
('electric-solid',    'Electric guitar (solidbody)','guitar', 'electric', 20),
('electric-semi',     'Electric guitar (semi-hollow)','guitar','electric',21),
('electric-hollow',   'Electric guitar (hollow)',   'guitar', 'electric', 22),
('twelve-string',     '12-string guitar',           'guitar', 'acoustic', 23),
('resonator',         'Dobro / resonator',          'guitar', 'resonator',30),
('pedal-steel',       'Pedal steel',                'steel',  'pedal',    31),
('lap-steel',         'Lap steel',                  'steel',  'lap',      32),
('electric-bass',     'Electric bass',              'bass',   'electric', 40),
('upright-bass',      'Upright bass',               'bass',   'acoustic', 41),
('mandolin',          'Mandolin',                   'mando',  NULL,       50),
('mandola',           'Mandola',                    'mando',  NULL,       51),
('banjo-5',           'Banjo (5-string)',           'banjo',  '5-string', 60),
('banjo-tenor',       'Banjo (tenor)',              'banjo',  'tenor',    61),
('banjo-plectrum',    'Banjo (plectrum)',           'banjo',  'plectrum', 62),
('fiddle',            'Fiddle / violin',            'bowed',  'violin',   70),
('viola',             'Viola',                      'bowed',  'viola',    71),
('cello',             'Cello',                      'bowed',  'cello',    72),
('ukulele',           'Ukulele',                    'ukulele',NULL,       80),
('autoharp',          'Autoharp',                   'other',  NULL,       90),
('hurdy-gurdy',       'Hurdy-gurdy',                'other',  NULL,       91),
('harp',              'Harp',                       'other',  NULL,       92),
('dulcimer',          'Dulcimer (mountain)',        'other',  NULL,       93);

-- ----- skills (50) ------------------------------------------------------
INSERT INTO skill_catalog (slug, name, category, sort_order) VALUES
-- repair
('fret-leveling',     'Fret leveling',              'repair',  10),
('fret-crowning',     'Fret crowning',              'repair',  11),
('refret',            'Refret',                     'repair',  12),
('partial-refret',    'Partial refret',             'repair',  13),
('neck-reset',        'Neck reset',                 'repair',  14),
('setup',             'Full setup',                 'repair',  15),
('intonation',        'Intonation',                 'repair',  16),
('action-adjustment', 'Action adjustment',          'repair',  17),
('nut-work',          'Nut cut / replace',          'repair',  18),
('saddle-fitting',    'Saddle fitting',             'repair',  19),
('crack-repair',      'Crack repair (top/back)',    'repair',  20),
('top-crack',         'Top crack repair',           'repair',  21),
('brace-repair',      'Brace re-glue / replace',    'repair',  22),
('bridge-replacement','Bridge replacement',         'repair',  23),
('headstock-break',   'Headstock break repair',     'repair',  24),
('binding-repair',    'Binding repair / replace',   'repair',  25),
('vintage-restoration','Vintage restoration',       'repair',  26),
('truss-rod',         'Truss rod service',          'repair',  27),
('tuner-install',     'Tuner install / replace',    'repair',  28),
-- build
('flat-top-build',    'Flat-top build',             'build',   40),
('archtop-build',     'Archtop build',              'build',   41),
('electric-build',    'Solidbody build',            'build',   42),
('bass-build',        'Bass build',                 'build',   43),
('mandolin-build',    'Mandolin build',             'build',   44),
('neck-build',        'Neck build / refinish',      'build',   45),
('inlay-work',        'Inlay work',                 'build',   46),
('binding-install',   'Binding install',            'build',   47),
('finish-touchup',    'Finish touch-up',            'build',   48),
('full-refinish',     'Full refinish',              'build',   49),
-- electronics
('pickup-install',    'Pickup install',             'electronics', 60),
('rewiring',          'Rewiring / harness',         'electronics', 61),
('soldering',         'Soldering',                  'electronics', 62),
('preamp-install',    'Acoustic preamp install',    'electronics', 63),
('shielding',         'Shielding / noise reduction','electronics', 64),
('pot-swap',          'Pot / cap swap',             'electronics', 65),
('pickup-winding',    'Pickup winding',             'electronics', 66),
-- tour
('on-site-repair',    'On-site field repair',       'tour',    80),
('tour-tech',         'Tour tech / guitar tech',    'tour',    81),
('rig-design',        'Rig design',                 'tour',    82),
('cable-fabrication', 'Cable fabrication',          'tour',    83),
-- fabrication
('cad-design',        'CAD design',                 'fabrication', 100),
('machinist-work',    'Machinist / CNC work',       'fabrication', 101),
('fixture-making',    'Fixture making',             'fabrication', 102),
('jig-design',        'Jig design',                 'fabrication', 103),
('3d-print',          '3D printing for luthiery',   'fabrication', 104),
-- studio
('recording',         'Recording engineering',      'studio',  120),
('mixing',            'Mixing',                     'studio',  121),
('amp-modeling',      'Amp / impulse capture',      'studio',  122),
('mastering',         'Mastering',                  'studio',  123),
('reamping',          'Reamping',                   'studio',  124);

-- ----- credentials (60) -------------------------------------------------
INSERT INTO credential_catalog (slug, category, issuer, program) VALUES
-- warranty (factory-authorized service)
('taylor-authorized',     'warranty','Taylor Guitars',            'Authorized warranty service'),
('martin-authorized',     'warranty','Martin Guitar',             'Authorized warranty service'),
('fender-authorized',     'warranty','Fender Musical Instruments','Authorized warranty service'),
('gibson-authorized',     'warranty','Gibson Brands',             'Authorized warranty service'),
('gretsch-authorized',    'warranty','Gretsch Guitars',           'Authorized warranty service'),
('prs-authorized',        'warranty','PRS Guitars',               'Authorized warranty service'),
('rickenbacker-authorized','warranty','Rickenbacker',             'Authorized warranty service'),
('collings-authorized',   'warranty','Collings Guitars',          'Authorized warranty service'),
('larrivee-authorized',   'warranty','Larrivée',                  'Authorized warranty service'),
('yamaha-authorized',     'warranty','Yamaha',                    'Authorized warranty service'),
('takamine-authorized',   'warranty','Takamine',                  'Authorized warranty service'),
('santa-cruz-authorized', 'warranty','Santa Cruz Guitar Company', 'Authorized warranty service'),
('breedlove-authorized',  'warranty','Breedlove Guitars',         'Authorized warranty service'),
('guild-authorized',      'warranty','Guild Guitars',             'Authorized warranty service'),
('gibson-historic-authorized','warranty','Gibson Custom Shop',    'Historic series authorized service'),
('rainsong-authorized',   'warranty','Rainsong',                  'Authorized warranty service'),
('emerald-authorized',    'warranty','Emerald Guitars',           'Authorized warranty service'),
('national-authorized',   'warranty','National Reso-Phonic',      'Authorized warranty service'),
('weber-authorized',      'warranty','Weber Mandolins',           'Authorized warranty service'),
('eastman-authorized',    'warranty','Eastman Guitars',           'Authorized warranty service'),
-- certification
('plek-pro',              'certification','Plek',                 'Plek Pro Certified Technician'),
('plek-standard',         'certification','Plek',                 'Plek Standard Certified Technician'),
('plek-master',           'certification','Plek',                 'Plek Master Technician'),
('emg-certified',         'certification','EMG Pickups',          'Certified installer'),
('fishman-certified',     'certification','Fishman',              'Certified acoustic preamp installer'),
('lr-baggs-certified',    'certification','L.R. Baggs',           'Certified installer'),
('graphtech-certified',   'certification','Graph Tech',           'Authorized installer'),
('lollar-certified',      'certification','Lollar Pickups',       'Certified installer'),
('fralin-certified',      'certification','Lindy Fralin',         'Certified installer'),
('bare-knuckle-cert',     'certification','Bare Knuckle Pickups', 'Certified installer'),
-- education
('berklee-guitar-repair', 'education','Berklee College of Music', 'Guitar Repair Certificate'),
('roberto-venn',          'education','Roberto-Venn',             'School of Luthiery — Diploma'),
('galloup',               'education','Galloup School of Guitar Building & Repair', 'Diploma'),
('galloup-master',        'education','Galloup School',           'Master Luthier Program'),
('galaxy',                'education','Galaxy Instrument School', 'Diploma'),
('summit-luthier',        'education','Summit School',            'Luthier Certificate'),
('mi-guitar-craft',       'education','Musicians Institute',      'Guitar Craft Program'),
('northwest-luthier',     'education','Northwest School of Wooden Boatbuilding', 'Stringed-Instrument Construction'),
('totnes',                'education','Totnes School of Guitarmaking', 'Diploma'),
('chicago-luthier',       'education','Chicago School of Violin Making', 'Diploma'),
('redwood-music-college', 'education','Redwood Music College',    'Luthier Diploma'),
('lutherie-quebec',       'education','École Nationale de Lutherie (Québec)', 'Diploma'),
('newark-violin',         'education','Newark School of Violin Making', 'Diploma'),
('arvada-violin',         'education','Bryan Galloup Master',     'Master Apprenticeship'),
('appalachian-luthier',   'education','Appalachian State Univ.',  'Stringed-instrument concentration'),
-- membership
('guild-american-luthiers','membership','Guild of American Luthiers', 'Member'),
('asia',                  'membership','Association of Stringed Instrument Artisans', 'Member'),
('afm-local',             'membership','American Federation of Musicians', 'Local member'),
('pra',                   'membership','Professional Repairers Alliance', 'Member'),
('asg',                   'membership','American Society of Guitarmakers','Member'),
('vsa',                   'membership','Violin Society of America','Member'),
('catgut-acoustical',     'membership','Catgut Acoustical Society','Member'),
('guitarmaker-newsletter','membership','Guitarmaker International','Subscriber/contributor'),
-- license
('contractor-license',    'license','State (varies)',             'Contractor license (general)'),
('business-license',      'license','City/County',                'Business license'),
('hazmat-handler',        'license','OSHA',                       'Hazardous materials handler'),
('lead-paint-cert',       'license','EPA',                        'Lead-paint renovation certification'),
('vintage-export-license','license','US Fish & Wildlife',         'CITES export license (vintage materials)'),
('ce-marking-tech',       'license','EU CE',                      'CE-marking technician'),
('class-iv-laser',        'license','OSHA',                       'Class IV laser operator (finish work)');

-- ----- scenes (14) ------------------------------------------------------
INSERT INTO scene_tags (slug, name, sort_order) VALUES
('bluegrass', 'Bluegrass', 10),
('rock',      'Rock',      20),
('country',   'Country',   30),
('jazz',      'Jazz',      40),
('classical', 'Classical', 50),
('gospel',    'Gospel',    60),
('world',     'World',     70),
('electronic','Electronic',80),
('theater',   'Theater / pit', 90),
('session',   'Session',   100),
('vintage',   'Vintage / collectible', 110),
('boutique',  'Boutique builds', 120),
('studio',    'Studio',    130),
('touring',   'Touring',   140);

COMMIT;
