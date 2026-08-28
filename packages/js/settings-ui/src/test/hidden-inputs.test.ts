/**
 * Internal dependencies
 */
import { getHiddenInputs } from '../hidden-inputs';
import type { SettingsUIField, SettingsValue } from '../types';

const formPostField = (
	overrides: Partial< SettingsUIField > = {}
): SettingsUIField => ( {
	id: 'quantity',
	label: 'Quantity',
	type: 'number',
	save: { adapter: 'form_post', name: 'quantity' },
	...overrides,
} );

describe( 'getHiddenInputs', () => {
	it( 'serializes checkbox values for legacy form posts', () => {
		expect(
			getHiddenInputs(
				{
					id: 'enabled',
					label: 'Enabled',
					type: 'checkbox',
					save: { adapter: 'form_post', name: 'enabled' },
				},
				true
			)
		).toEqual( [ { name: 'enabled', value: 'yes' } ] );
	} );

	it.each( [ 'yes', '1' ] )(
		'treats the legacy truthy-string checkbox value %p as checked',
		( legacyValue ) => {
			expect(
				getHiddenInputs(
					{
						id: 'enabled',
						label: 'Enabled',
						type: 'checkbox',
						save: { adapter: 'form_post', name: 'enabled' },
					},
					legacyValue
				)
			).toEqual( [ { name: 'enabled', value: 'yes' } ] );
		}
	);

	it( 'serializes changed checkbox values instead of their original form representation', () => {
		expect(
			getHiddenInputs(
				{
					id: 'enabled',
					label: 'Enabled',
					type: 'checkbox',
					save: {
						adapter: 'form_post',
						name: 'enabled',
						initialValue: 'yes',
					},
				},
				false,
				true
			)
		).toEqual( [ { name: 'enabled', value: 'no' } ] );
	} );

	it( 'serializes array values with bracketed field names', () => {
		expect(
			getHiddenInputs(
				{
					id: 'methods',
					label: 'Methods',
					type: 'array',
					save: { adapter: 'form_post', name: 'methods' },
				},
				[ 'card', 'link' ]
			)
		).toEqual( [
			{ name: 'methods[]', value: 'card' },
			{ name: 'methods[]', value: 'link' },
		] );
	} );

	it( 'does not serialize fields using the none adapter', () => {
		expect(
			getHiddenInputs(
				{
					id: 'info',
					label: 'Info',
					type: 'info',
					save: { adapter: 'none' },
				},
				''
			)
		).toEqual( [] );
	} );

	it( 'serializes the current value in a two-argument call even when an original form value exists', () => {
		const field = formPostField( {
			save: {
				adapter: 'form_post',
				name: 'quantity',
				initialValue: '02',
			},
		} );

		expect( getHiddenInputs( field, 2 ) ).toEqual( [
			{ name: 'quantity', value: '2' },
		] );
	} );

	it.each( [
		{
			label: 'save adapter',
			field: formPostField( {
				save: { adapter: 'custom', name: 'quantity' },
			} ),
			message: 'Save adapter "custom" is not supported.',
		},
		{
			label: 'form-post field name',
			field: formPostField( {
				save: {
					adapter: 'form_post',
					name: 'settings[group][quantity]',
				},
			} ),
			message:
				'Form-post field name "settings[group][quantity]" is not supported.',
		},
		{
			label: 'list initialValue for a scalar field',
			field: formPostField( {
				save: {
					adapter: 'form_post',
					name: 'quantity',
					initialValue: [ '1', '2' ],
				},
			} ),
			message:
				'Field "quantity" has a list initialValue but is not an array field.',
		},
	] )(
		'handles an unsupported $label gracefully by default',
		( { field, message } ) => {
			const consoleError = jest
				.spyOn( console, 'error' )
				.mockImplementation( () => undefined );

			expect( getHiddenInputs( field, 2, 1 ) ).toEqual( [] );
			expect( consoleError ).toHaveBeenCalledWith(
				`[WooCommerce settings UI] ${ message }`,
				{ field }
			);

			consoleError.mockRestore();
		}
	);

	it.each( [
		{
			label: 'save adapter',
			field: formPostField( {
				save: { adapter: 'custom', name: 'quantity' },
			} ),
			message: 'Save adapter "custom" is not supported.',
		},
		{
			label: 'form-post field name',
			field: formPostField( {
				save: {
					adapter: 'form_post',
					name: 'settings[group][quantity]',
				},
			} ),
			message:
				'Form-post field name "settings[group][quantity]" is not supported.',
		},
		{
			label: 'list initialValue for a scalar field',
			field: formPostField( {
				save: {
					adapter: 'form_post',
					name: 'quantity',
					initialValue: [ '1', '2' ],
				},
			} ),
			message:
				'Field "quantity" has a list initialValue but is not an array field.',
		},
	] )(
		'throws for an unsupported $label in strict mode',
		( { field, message } ) => {
			expect( () =>
				getHiddenInputs( field, 2, 1, { strict: true } )
			).toThrow( message );
		}
	);

	it( 'preserves the original form representation while the canonical value is unchanged', () => {
		const field = formPostField( {
			save: {
				adapter: 'form_post',
				name: 'quantity',
				initialValue: '02',
			},
		} );

		expect( getHiddenInputs( field, 2, 2 ) ).toEqual( [
			{ name: 'quantity', value: '02' },
		] );
	} );

	it( 'serializes an edited canonical value', () => {
		const field = formPostField( {
			save: {
				adapter: 'form_post',
				name: 'quantity',
				initialValue: '02',
			},
		} );

		expect( getHiddenInputs( field, 3, 2 ) ).toEqual( [
			{ name: 'quantity', value: '3' },
		] );
	} );

	it( 'serializes a cleared canonical number as an empty string', () => {
		const field = formPostField( {
			save: {
				adapter: 'form_post',
				name: 'quantity',
				initialValue: '02',
			},
		} );

		expect( getHiddenInputs( field, null, 2 ) ).toEqual( [
			{ name: 'quantity', value: '' },
		] );
	} );

	it.each< [ SettingsValue, SettingsValue, boolean ] >( [
		[ 0, 0, true ],
		[ 0, '0', false ],
		[ '', '', true ],
		[ '', false, false ],
		[ false, false, true ],
		[ false, null, false ],
		[ null, null, true ],
		[ null, '', false ],
		[ [], [], true ],
		[ [], [ '' ], false ],
	] )(
		'distinguishes exact falsey values (%p and %p)',
		( currentValue, initialValue, isUnchanged ) => {
			const field = formPostField( {
				save: {
					adapter: 'form_post',
					name: 'quantity',
					initialValue: 'original',
				},
			} );
			const [ input ] = getHiddenInputs(
				field,
				currentValue,
				initialValue
			);

			expect( input?.value === 'original' ).toBe( isUnchanged );
		}
	);

	it( 'serializes one-level nested names', () => {
		expect(
			getHiddenInputs(
				formPostField( {
					save: {
						adapter: 'form_post',
						name: 'settings[quantity]',
					},
				} ),
				2,
				1
			)
		).toEqual( [ { name: 'settings[quantity]', value: '2' } ] );
	} );

	it( 'preserves original array entries with bracketed one-level nested names', () => {
		expect(
			getHiddenInputs(
				formPostField( {
					type: 'array',
					save: {
						adapter: 'form_post',
						name: 'settings[methods]',
						initialValue: [ 'card', 'link' ],
					},
				} ),
				[ 'card', 'link' ],
				[ 'card', 'link' ]
			)
		).toEqual( [
			{ name: 'settings[methods][]', value: 'card' },
			{ name: 'settings[methods][]', value: 'link' },
		] );
	} );

	it( 'serializes current array entries with existing bracketed one-level nested names', () => {
		expect(
			getHiddenInputs(
				formPostField( {
					type: 'array',
					save: {
						adapter: 'form_post',
						name: 'settings[methods][]',
					},
				} ),
				[ 'card', 'link' ]
			)
		).toEqual( [
			{ name: 'settings[methods][]', value: 'card' },
			{ name: 'settings[methods][]', value: 'link' },
		] );
	} );

	it( 'keeps disabled fields in the form-post entry list', () => {
		expect(
			getHiddenInputs( formPostField( { disabled: true } ), 2, 1 )
		).toEqual( [ { name: 'quantity', value: '2' } ] );
	} );

	it( 'keeps hidden fields in the form-post entry list', () => {
		expect(
			getHiddenInputs(
				formPostField( {
					visibility: { controller: 'enabled', value: true },
				} ),
				2,
				1
			)
		).toEqual( [ { name: 'quantity', value: '2' } ] );
	} );

	it( 'preserves the original form representation for an unchanged canonical datetime', () => {
		expect(
			getHiddenInputs(
				formPostField( {
					type: 'datetime-local',
					save: {
						adapter: 'form_post',
						name: 'starts_at',
						initialValue: '2026-01-01T12:00',
					},
				} ),
				'2026-01-01T12:00:00Z',
				'2026-01-01T12:00:00Z'
			)
		).toEqual( [ { name: 'starts_at', value: '2026-01-01T12:00' } ] );
	} );

	it( 'serializes an edited canonical datetime back to store-local form', () => {
		expect(
			getHiddenInputs(
				formPostField( {
					type: 'datetime-local',
					save: {
						adapter: 'form_post',
						name: 'starts_at',
						initialValue: '2026-01-01T12:00',
					},
				} ),
				'2026-01-01T13:30:00Z',
				'2026-01-01T12:00:00Z'
			)
		).toEqual( [ { name: 'starts_at', value: '2026-01-01T13:30:00' } ] );
	} );
} );
