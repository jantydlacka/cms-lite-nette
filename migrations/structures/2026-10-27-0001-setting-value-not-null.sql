UPDATE `setting` SET `value` = '' WHERE `value` IS NULL;
ALTER TABLE `setting` MODIFY `value` TEXT NOT NULL;
