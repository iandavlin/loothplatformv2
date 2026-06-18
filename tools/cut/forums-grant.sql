-- Cut step 11d — archive-poc role needs to read forums.topic/forum directly
-- (the front-page "Active discussions" row; the content_item kind=discussion
-- sync was retired 6/5). Without this the front page 500s for members.
-- Run on the cut box's PG (database: looth) as a superuser.
GRANT USAGE ON SCHEMA forums TO "archive-poc";
GRANT SELECT ON forums.topic, forums.forum TO "archive-poc";
