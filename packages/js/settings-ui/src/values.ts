/**
 * External dependencies
 */
import { date, getDate } from '@wordpress/date';

/**
 * Internal dependencies
 */
import type { SettingsValue } from './types';

const STORE_LOCAL_DATETIME_FORMAT = 'Y-m-d\\TH:i:s';
const CANONICAL_DATETIME_FORMAT = 'Y-m-d\\TH:i:sP';

type NormalizedDecimal = [ string, number ];

const normalizeDecimalString = ( value: string ): NormalizedDecimal | null => {
	// Accept an optional sign, an integer with an optional fraction or a bare
	// fraction, and an optional signed exponent.
	const matches = value.match(
		/^([+-]?)(?:(\d+)(?:\.(\d*))?|\.(\d+))(?:[eE]([+-]?\d+))?$/
	);
	if ( ! matches ) {
		return null;
	}

	const whole = matches[ 2 ] || '';
	const fraction = matches[ 3 ] || matches[ 4 ] || '';
	let digits = `${ whole }${ fraction }`.replace( /^0+/, '' );
	if ( digits === '' ) {
		return [ '0', 0 ];
	}

	const exponent = Number( matches[ 5 ] || '0' );
	let power = exponent - fraction.length;
	if (
		! Number.isSafeInteger( exponent ) ||
		! Number.isSafeInteger( power )
	) {
		return null;
	}

	const trailingZeroCount = digits.match( /0+$/ )?.[ 0 ].length || 0;
	if ( trailingZeroCount > 0 ) {
		digits = digits.slice( 0, -trailingZeroCount );
		power += trailingZeroCount;
	}
	if ( ! Number.isSafeInteger( power ) ) {
		return null;
	}

	return [ matches[ 1 ] === '-' ? `-${ digits }` : digits, power ];
};

const decimalStringsRepresentSameValue = ( left: string, right: string ) => {
	const normalizedLeft = normalizeDecimalString( left );
	const normalizedRight = normalizeDecimalString( right );

	return (
		normalizedLeft !== null &&
		normalizedRight !== null &&
		normalizedLeft[ 0 ] === normalizedRight[ 0 ] &&
		normalizedLeft[ 1 ] === normalizedRight[ 1 ]
	);
};

export const areValuesEqual = ( a: SettingsValue, b: SettingsValue ) => {
	if ( Array.isArray( a ) || Array.isArray( b ) ) {
		return (
			Array.isArray( a ) &&
			Array.isArray( b ) &&
			a.length === b.length &&
			a.every( ( value, index ) => value === b[ index ] )
		);
	}

	return a === b;
};

export const valueMatchesVisibilityRule = (
	value: SettingsValue,
	expected: SettingsValue | SettingsValue[] | undefined
) => {
	const expectedValues = Array.isArray( expected )
		? expected
		: [ expected ?? true ];

	return expectedValues.some( ( expectedValue ) =>
		areValuesEqual( value, expectedValue )
	);
};

export const toCanonicalNumberValue = (
	value: string,
	integerOnly = false
): number | null => {
	if ( value.trim() === '' ) {
		return null;
	}

	const numberValue = Number( value );

	if ( ! Number.isFinite( numberValue ) ) {
		return null;
	}

	if (
		( Number.isInteger( numberValue ) &&
			! Number.isSafeInteger( numberValue ) ) ||
		( integerOnly && ! Number.isInteger( numberValue ) ) ||
		! decimalStringsRepresentSameValue(
			value.trim(),
			String( numberValue )
		)
	) {
		return null;
	}

	return numberValue;
};

export const toStoreLocalDateTime = ( value: SettingsValue ) => {
	if ( typeof value !== 'string' || value === '' ) {
		return '';
	}

	return date( STORE_LOCAL_DATETIME_FORMAT, value );
};

export const toCanonicalDateTime = ( value: string ): string | null => {
	if ( value === '' ) {
		return null;
	}

	const parsed = getDate( value );
	if ( Number.isNaN( parsed.getTime() ) ) {
		return null;
	}

	return date( CANONICAL_DATETIME_FORMAT, parsed );
};
