-- rollback for bb-avatar backfill (5 rows)
BEGIN;
UPDATE users SET avatar_url='https://www.gravatar.com/avatar/5a1111a169f022571fd00b6e293a3a19?d=https%3A%2F%2Fdev.loothgroup.com%2Fwp-content%2Fuploads%2Favatars%2F0%2F674d94a75ed58-bpfull.jpg&s=400', avatar_version=0 WHERE id=5;
UPDATE users SET avatar_url='https://www.gravatar.com/avatar/f03b89d83473cf662e1a74640478dae2?d=https%3A%2F%2Fdev.loothgroup.com%2Fwp-content%2Fuploads%2Favatars%2F0%2F674d94a75ed58-bpfull.jpg&s=400', avatar_version=0 WHERE id=6;
UPDATE users SET avatar_url='https://www.gravatar.com/avatar/ff2c22a855a2d63e87e09cb95b25d1e3?d=https%3A%2F%2Fdev.loothgroup.com%2Fwp-content%2Fuploads%2Favatars%2F0%2F674d94a75ed58-bpfull.jpg&s=400', avatar_version=0 WHERE id=7;
UPDATE users SET avatar_url='https://www.gravatar.com/avatar/064136ef866bc185f9002f2abe4a144c?d=https%3A%2F%2Fdev.loothgroup.com%2Fwp-content%2Fuploads%2Favatars%2F0%2F674d94a75ed58-bpfull.jpg&s=400', avatar_version=0 WHERE id=12;
UPDATE users SET avatar_url='https://www.gravatar.com/avatar/a6278f2cfdd171ab66c0b06759e9a4c5?d=https%3A%2F%2Fdev.loothgroup.com%2Fwp-content%2Fuploads%2Favatars%2F0%2F674d94a75ed58-bpfull.jpg&s=400', avatar_version=0 WHERE id=13;
COMMIT;
