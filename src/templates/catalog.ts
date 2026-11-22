/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { t } from '@nextcloud/l10n'

/**
 * The words of the starter templates that ship with the app (lib/Forms/Templates). The templates are kept in
 * English on the server. Each English text is listed here as a translatable string, and the map goes to the
 * server with the request, which swaps the text of the new form (docs/SCHEMA.md section 13.4). A test checks that
 * every word of every template is in this list.
 *
 * This is a function and not a constant so that it reads the language the page was loaded in.
 */
export function templateStrings(): Record<string, string> {
	return {
		'Contact form': t('webforms', 'Contact form'),
		'Name, email and a message.': t('webforms', 'Name, email and a message.'),
		'Contact us': t('webforms', 'Contact us'),
		'Send us a message and we will get back to you.': t('webforms', 'Send us a message and we will get back to you.'),
		'Send message': t('webforms', 'Send message'),
		'Name': t('webforms', 'Name'),
		'Email': t('webforms', 'Email'),
		'Subject': t('webforms', 'Subject'),
		'Message': t('webforms', 'Message'),
		'Event RSVP': t('webforms', 'Event RSVP'),
		'Count guests and note dietary needs.': t('webforms', 'Count guests and note dietary needs.'),
		'RSVP': t('webforms', 'RSVP'),
		'Let us know if you can make it.': t('webforms', 'Let us know if you can make it.'),
		'Send RSVP': t('webforms', 'Send RSVP'),
		'Will you attend?': t('webforms', 'Will you attend?'),
		'Yes, I will be there': t('webforms', 'Yes, I will be there'),
		'No, I cannot make it': t('webforms', 'No, I cannot make it'),
		'Number of guests': t('webforms', 'Number of guests'),
		'Dietary needs': t('webforms', 'Dietary needs'),
		'Vegetarian': t('webforms', 'Vegetarian'),
		'Vegan': t('webforms', 'Vegan'),
		'Gluten free': t('webforms', 'Gluten free'),
		'Allergies': t('webforms', 'Allergies'),
		'Anything else we should know?': t('webforms', 'Anything else we should know?'),
		'Feedback survey': t('webforms', 'Feedback survey'),
		'A rating, what worked and what did not.': t('webforms', 'A rating, what worked and what did not.'),
		'Share your feedback': t('webforms', 'Share your feedback'),
		'Your answers help us improve.': t('webforms', 'Your answers help us improve.'),
		'Send feedback': t('webforms', 'Send feedback'),
		'How would you rate your experience?': t('webforms', 'How would you rate your experience?'),
		'What did you like?': t('webforms', 'What did you like?'),
		'What could be better?': t('webforms', 'What could be better?'),
		'How likely are you to recommend us?': t('webforms', 'How likely are you to recommend us?'),
		'Not likely': t('webforms', 'Not likely'),
		'Very likely': t('webforms', 'Very likely'),
		'Email (optional, if you want a reply)': t('webforms', 'Email (optional, if you want a reply)'),
		'Quick poll': t('webforms', 'Quick poll'),
		'One question, a few choices.': t('webforms', 'One question, a few choices.'),
		'Pick one answer.': t('webforms', 'Pick one answer.'),
		'Vote': t('webforms', 'Vote'),
		'Which option do you prefer?': t('webforms', 'Which option do you prefer?'),
		'Option A': t('webforms', 'Option A'),
		'Option B': t('webforms', 'Option B'),
		'Option C': t('webforms', 'Option C'),
		'Comment (optional)': t('webforms', 'Comment (optional)'),
		'Event registration': t('webforms', 'Event registration'),
		'Collect attendee details and consent.': t('webforms', 'Collect attendee details and consent.'),
		'Registration': t('webforms', 'Registration'),
		'Register your place.': t('webforms', 'Register your place.'),
		'Register': t('webforms', 'Register'),
		'Full name': t('webforms', 'Full name'),
		'Phone': t('webforms', 'Phone'),
		'Organization': t('webforms', 'Organization'),
		'Session': t('webforms', 'Session'),
		'Morning': t('webforms', 'Morning'),
		'Afternoon': t('webforms', 'Afternoon'),
		'Full day': t('webforms', 'Full day'),
		'Consent': t('webforms', 'Consent'),
		'I agree that my details may be used to organize this event.': t('webforms', 'I agree that my details may be used to organize this event.'),
		'Support request': t('webforms', 'Support request'),
		'Topic, urgency and details.': t('webforms', 'Topic, urgency and details.'),
		'Describe the problem and we will look into it.': t('webforms', 'Describe the problem and we will look into it.'),
		'Submit request': t('webforms', 'Submit request'),
		'Topic': t('webforms', 'Topic'),
		'Account': t('webforms', 'Account'),
		'Billing': t('webforms', 'Billing'),
		'Something is broken': t('webforms', 'Something is broken'),
		'Other': t('webforms', 'Other'),
		'How urgent is it?': t('webforms', 'How urgent is it?'),
		'Low': t('webforms', 'Low'),
		'Normal': t('webforms', 'Normal'),
		'High': t('webforms', 'High'),
		'Details': t('webforms', 'Details'),
		'General': t('webforms', 'General'),
		'Events': t('webforms', 'Events'),
		'Feedback': t('webforms', 'Feedback'),
		'Support': t('webforms', 'Support'),
	}
}

/** The translation of a starter template's name, description or category. Unknown text comes back as it is. */
export function translateTemplateText(english: string): string {
	return templateStrings()[english] ?? english
}
