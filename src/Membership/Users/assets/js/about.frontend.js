(function($, Membership) {

	$('.about-tabs .item').mpTab();
	var $fields = $('.mp-field'),
		$dateFields = $fields.find('.date-field');
	var error = false;

	if ($dateFields.length) {
		$dateFields.each(function () {
			var $dateField = $(this),
				$realField = $dateField.next();

			new Pikaday({
				yearRange: 100,
				field: this,
				format: $dateField.attr('data-format'),
				onSelect: function() {
					$realField.val(this.getMoment().format('YYYY-MM-DD'));
				}
			});
		})

	}

	$fields.find('select.dropdown').mpDropdown();


	function Field($field) {

		this.$el = $field;
		this.type = $field.attr('data-field-type');
		this.name = $field.attr('data-field-name');

		var self = this;

		$field.find('.field-edit-action').on('click', function () {
			self.editAction()
		});
	}

	Field.prototype.editAction = function() {

		var self = this,
			$fieldData = this.$el.find('.mp-field-data'),
			$fieldEdit = this.$el.find('.mp-field-edit'),
			$fieldValidations = this.$el.data('validations'),
			$actionButtons = $fieldEdit.find('.edit-action-buttons button'),
			$saveActionButton = $actionButtons.filter('.save-action'),
			$cancelActionButton = $actionButtons.filter('.cancel-action'),
			storedCurrentFieldData = this.getFieldData();

		$fieldEdit.show();
		$fieldData.hide();

		$saveActionButton.off('click').on('click', function () {
			$saveActionButton.addClass('loading');
			$actionButtons.attr('disabled', true).addClass('disabled');
			var fieldData = self.getFieldData();

			if (typeof $fieldValidations !== 'undefined') {
                self.hideErrorMessage();
                fieldData = self.validateField($saveActionButton, fieldData, $fieldValidations);
			}

			if (!error) {
                self.updateField(fieldData);

                Membership.api.users.saveField({
                    fieldName: self.name,
                    fieldData: fieldData
                }).then(function () {
                    $actionButtons.removeAttr('disabled').removeClass('disabled loading');
                    self.hideErrorMessage();
                    $fieldEdit.hide();
                    $fieldData.show();
                });
            } else {
                $actionButtons.removeAttr('disabled').removeClass('disabled loading');
			}
		});

		$cancelActionButton.off('click').on('click', function () {
			self.setFieldData(storedCurrentFieldData);
			$actionButtons.removeAttr('disabled').removeClass('disabled loading');
			$fieldEdit.hide();
			$fieldData.show();
		});
	};

	Field.prototype.getFieldData = function() {

		var data,
			$editContainer = this.$el.find('.mp-field-edit');

		switch (this.type) {
			case 'text':
			case 'email':
			case 'password':
			case 'numeric':
				data = $editContainer.find('input').val();
				break;
			case 'date':
				data = $editContainer.find('input[type="hidden"]').val();
				break;
			case 'drop':
				data = $editContainer.find('select').val();
				break;
			case 'checkbox':
				data = [];
				$editContainer.find('input:checked').each(function() {
					data.push(this.value);
				});
				break;
			case 'scroll':
				data = [];
				$editContainer.find('select option:selected').each(function() {
					data.push(this.value);
				});
				break;
			case 'radio':
				data = $editContainer.find('input:checked').attr('value');
				break;
		}

		return data;
	};

	Field.prototype.setFieldData = function(data) {

		var $editContainer = this.$el.find('.mp-field-edit');

		switch (this.type) {
			case 'text':
			case 'email':
			case 'password':
			case 'numeric':
				this.$el.find('.mp-field-edit input').val(data);
				break;
			case 'date':
				this.$el.find('.mp-field-edit input[type="hidden"]').val(data);
				break;
			case 'drop':
				this.$el.find('.mp-field-edit select').val(data);
				break;
			case 'checkbox':
				$editContainer.find('input').prop('checked', false);
				data.forEach(function (el) {
					$editContainer.find('input[value="' + el + '"]').prop('checked', true);
				});
				break;
			case 'scroll':
				$editContainer.find('select option').prop('selected', false);
				data.forEach(function (el) {
					$editContainer.find('select option[value="' + el + '"]').prop('selected', true);
				});
				break;
			case 'radio':
				$editContainer.find('input').prop('selected', false);
				$editContainer.find('input[value="' + data + '"]').prop('selected', true);
				break;
		}
	};

	Field.prototype.updateField = function(data) {

		var $fieldDataContainer = this.$el.find('.mp-field-data p'),
			$editContainer = this.$el.find('.mp-field-edit'),
			title = [];

		switch (this.type) {
			case 'text':
			case 'email':
			case 'password':
			case 'numeric':
				$fieldDataContainer.text(data);
				break;
			case 'date':
				var dateFieldInput = $editContainer.find('.date-field'),
					format = dateFieldInput.attr('data-format');

				$fieldDataContainer.text(moment(data).format(format));
				break;
			case 'drop':
				$fieldDataContainer.text($editContainer.find('select option[value="' + data + '"]').text());
				break;
			case 'checkbox':
				data.forEach(function (el) {
					title.push($editContainer.find('input[value="' + el + '"]').next().text());
				});
				$fieldDataContainer.text(title.join(' • '));
				break;
			case 'scroll':
				$editContainer.find('select option:selected').each(function() {
					title.push($(this).text());
				});

				$fieldDataContainer.text(title.join(' • '));
				break;
			case 'radio':
				$fieldDataContainer.text(
					$editContainer.find('input:checked').next().text()
				);
				break;
		}
	};

    Field.prototype.validateField = function($saveActionButton, data, validations) {

        var self = this;

        switch (this.type) {
            case 'text':
            case 'email':
            case 'password':
            case 'numeric':
                if (validations.digits && !validations.alpha) {
                    data = data.match(/\d+/g);
                } else if (!validations.digits && validations.alpha) {
                    data = data.match(/\w+/g);
                }

                if (Array.isArray(data)) {
                    data = data[0];
                }

                if (!data) {
                    error = true;
                    self.showErrorMessage(Membership.trans('Error! The value of the field does not match the given pattern'));
                } else {
                    if (!new ValidateField(data, validations).validate(self)) {
                        error = true;
                    }
                }

                this.$el.find('.mp-field-edit input').val(data);
                break;
            default:
                break;
        }

        return data;
    };

    Field.prototype.showErrorMessage = function(message) {
        this.$el.find('.mp-field-edit').after('<div class="mp-error">'+ message+ '</div>');
    };

    Field.prototype.hideErrorMessage = function() {
        this.$el.find('.mp-error').remove();
    };

	function ValidateField(data, validations) {
	    this.data = data;
	    this.validations = validations;

        var self = this;
    }

    ValidateField.prototype.validate = function(Field){
	    var self = this;

        for (var key in self.validations) {
            var value = self.validations[key];

            switch (key) {
                case 'min_size':
                    if (value && self.data.length < value) {
                        Field.showErrorMessage(Membership.trans('Error! Minimum length for field is ' + value));
                        return false;
                    }
                    break;
                case 'max_size':
                    if (value && self.data.length > value) {
                        Field.showErrorMessage(Membership.trans('Error! Maximum length for field is '+ value));
                        return false;
                    }
                    break;
                case 'pattern':
                    if (value && self.data) {
                        let pattern = new RegExp(value);
                        if (!self.data.match(pattern)) {
                            Field.showErrorMessage(Membership.trans('Error! The value of the field does not match the given pattern'));
                            return false;
                        }
                    }
                    break;
                default:
                    break;
            }
        }

        error = false;

        return true;
    };

	$fields.each(function() {
		new Field($(this));
	});

})(jQuery, Membership);