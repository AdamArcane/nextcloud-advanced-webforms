<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getToken()
 * @method void setToken(string $token)
 * @method int getFormId()
 * @method void setFormId(int $formId)
 * @method string getFieldId()
 * @method void setFieldId(string $fieldId)
 * @method string|null getUserId()
 * @method void setUserId(?string $userId)
 * @method string getName()
 * @method void setName(string $name)
 * @method int getSize()
 * @method void setSize(int $size)
 * @method string getMime()
 * @method void setMime(string $mime)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class Upload extends Entity {
	protected $token;
	protected $formId;
	protected $fieldId;
	protected $userId;
	protected $name;
	protected $size;
	protected $mime;
	protected $createdAt;

	public function __construct() {
		$this->addType('token', 'string');
		$this->addType('formId', 'integer');
		$this->addType('fieldId', 'string');
		$this->addType('userId', 'string');
		$this->addType('name', 'string');
		$this->addType('size', 'integer');
		$this->addType('mime', 'string');
		$this->addType('createdAt', 'integer');
	}
}
