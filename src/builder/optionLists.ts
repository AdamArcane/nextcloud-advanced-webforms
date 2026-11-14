/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Ready-made option lists for select, radio and checkboxes fields (docs/SCHEMA.md 4.3).
// Choosing one copies it into the field, so a published form never changes when this file does.
// Values are lowercase standard codes. Labels are in the owner's language where `Intl.DisplayNames`
// can translate them (countries, currencies, languages, months, weekdays); subdivisions are English.

import type { FieldOption } from '../types.ts'

export type OptionListId = 'countries' | 'usStates' | 'caProvinces' | 'auStates' | 'months' | 'weekdays' | 'currencies' | 'languages'

const COUNTRY_CODES = 'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW'.split(' ')

const CURRENCY_CODES = 'AED ARS AUD BGN BRL CAD CHF CLP CNY COP CZK DKK EGP EUR GBP HKD HUF IDR ILS INR ISK JPY KRW MXN MYR NGN NOK NZD PHP PLN RON RUB SAR SEK SGD THB TRY TWD UAH USD VND ZAR'.split(' ')

const LANGUAGE_CODES = 'af ar bg bn ca cs da de el en es et fa fi fr he hi hr hu id it ja ko lt lv ms nl no pl pt ro ru sk sl sr sv sw th tr uk ur vi zh'.split(' ')

const MONTHS = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december']
const WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']

function pairs(prefix: string, entries: string): FieldOption[] {
	return entries.split('|').map((entry) => {
		const [code, label] = entry.split('=')
		return { value: prefix + code.toLowerCase(), label }
	})
}

const US_STATES = 'AL=Alabama|AK=Alaska|AZ=Arizona|AR=Arkansas|CA=California|CO=Colorado|CT=Connecticut|DE=Delaware|DC=District of Columbia|FL=Florida|GA=Georgia|HI=Hawaii|ID=Idaho|IL=Illinois|IN=Indiana|IA=Iowa|KS=Kansas|KY=Kentucky|LA=Louisiana|ME=Maine|MD=Maryland|MA=Massachusetts|MI=Michigan|MN=Minnesota|MS=Mississippi|MO=Missouri|MT=Montana|NE=Nebraska|NV=Nevada|NH=New Hampshire|NJ=New Jersey|NM=New Mexico|NY=New York|NC=North Carolina|ND=North Dakota|OH=Ohio|OK=Oklahoma|OR=Oregon|PA=Pennsylvania|RI=Rhode Island|SC=South Carolina|SD=South Dakota|TN=Tennessee|TX=Texas|UT=Utah|VT=Vermont|VA=Virginia|WA=Washington|WV=West Virginia|WI=Wisconsin|WY=Wyoming'
const CA_PROVINCES = 'AB=Alberta|BC=British Columbia|MB=Manitoba|NB=New Brunswick|NL=Newfoundland and Labrador|NS=Nova Scotia|NT=Northwest Territories|NU=Nunavut|ON=Ontario|PE=Prince Edward Island|QC=Quebec|SK=Saskatchewan|YT=Yukon'
const AU_STATES = 'ACT=Australian Capital Territory|NSW=New South Wales|NT=Northern Territory|QLD=Queensland|SA=South Australia|TAS=Tasmania|VIC=Victoria|WA=Western Australia'

function displayNames(locale: string, type: 'region' | 'currency' | 'language'): (code: string) => string {
	try {
		const names = new Intl.DisplayNames([locale], { type })
		return (code) => names.of(code) ?? code
	} catch {
		return (code) => code
	}
}

function sorted(options: FieldOption[], locale: string): FieldOption[] {
	return options.sort((a, b) => a.label.localeCompare(b.label, locale))
}

/** The options of a list, labelled for `locale` (a BCP 47 tag such as `en` or `de-CH`). */
export function optionList(id: OptionListId, locale: string): FieldOption[] {
	switch (id) {
		case 'countries': {
			const name = displayNames(locale, 'region')
			return sorted(COUNTRY_CODES.map((code) => ({ value: code.toLowerCase(), label: name(code) })), locale)
		}
		case 'usStates':
			return pairs('us_', US_STATES)
		case 'caProvinces':
			return pairs('ca_', CA_PROVINCES)
		case 'auStates':
			return pairs('au_', AU_STATES)
		case 'currencies': {
			const name = displayNames(locale, 'currency')
			return sorted(CURRENCY_CODES.map((code) => ({ value: code.toLowerCase(), label: code + ' ' + name(code) })), locale)
		}
		case 'languages': {
			const name = displayNames(locale, 'language')
			return sorted(LANGUAGE_CODES.map((code) => ({ value: code, label: name(code) })), locale)
		}
		case 'months':
			return MONTHS.map((value, index) => ({ value, label: new Date(2026, index, 1).toLocaleDateString(locale, { month: 'long' }) }))
		case 'weekdays':
			// 2026-06-01 is a Monday.
			return WEEKDAYS.map((value, index) => ({ value, label: new Date(2026, 5, 1 + index).toLocaleDateString(locale, { weekday: 'long' }) }))
	}
}

export const OPTION_LIST_IDS: OptionListId[] = ['countries', 'usStates', 'caProvinces', 'auStates', 'currencies', 'languages', 'months', 'weekdays']
