ALTER TABLE `blockchains` ADD `last_complete_block` INT NULL DEFAULT NULL AFTER `first_required_block`;