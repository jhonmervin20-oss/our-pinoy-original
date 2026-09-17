ALTER TABLE menu_items
    ADD COLUMN packaging_not_required TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Owner has explicitly confirmed this item needs no takeout packaging (e.g. canned drinks) -- suppresses the packaging-gap warning on Menu Items for this item permanently'
        AFTER available_takeout;
