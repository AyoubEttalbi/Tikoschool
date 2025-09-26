DROP TRIGGER IF EXISTS `check_offer_consistency_before_insert`;
DROP TRIGGER IF EXISTS `check_offer_consistency_before_update`;

DELIMITER $$
CREATE TRIGGER `check_offer_consistency_before_insert`
BEFORE INSERT ON `invoices`
FOR EACH ROW
BEGIN
    DECLARE membership_offer_id INT;
    SELECT offer_id INTO membership_offer_id FROM memberships WHERE id = NEW.membership_id;
    IF NEW.offer_id IS NOT NULL AND NEW.offer_id != membership_offer_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice offer_id must match membership offer_id';
    END IF;
END$$

CREATE TRIGGER `check_offer_consistency_before_update`
BEFORE UPDATE ON `invoices`
FOR EACH ROW
BEGIN
    DECLARE membership_offer_id INT;
    SELECT offer_id INTO membership_offer_id FROM memberships WHERE id = NEW.membership_id;
    IF NEW.offer_id IS NOT NULL AND NEW.offer_id != membership_offer_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice offer_id must match membership offer_id';
    END IF;
END$$
DELIMITER ;
