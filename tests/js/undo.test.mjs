import assert from 'node:assert/strict';
import test from 'node:test';
import { undoAccepted } from '../../src/admin/undo.mjs';

const accepted = ( count ) =>
	Array.from( { length: count }, ( _, i ) => ( {
		id: i + 1,
		state: 'generated',
	} ) );

for ( const count of [ 201, 500 ] ) {
	test( `undo reverses all ${ count } accepted labels`, async () => {
		const requests = [];
		let remaining = accepted( count );
		await undoAccepted(
			remaining,
			async ( ids ) => {
				requests.push( ids );
				return ids;
			},
			( items ) => {
				remaining = items;
			}
		);
		assert.deepEqual(
			requests.map( ( ids ) => ids.length ),
			count === 201 ? [ 200, 1 ] : [ 200, 200, 100 ]
		);
		assert.equal( remaining.length, 0 );
		assert.deepEqual(
			requests.flat(),
			accepted( count ).map( ( item ) => item.id )
		);
	} );
}

test( 'failed batches preserve all unfinished labels for a retry', async () => {
	let remaining = accepted( 500 );
	let calls = 0;
	await assert.rejects(
		undoAccepted(
			remaining,
			async ( ids ) => {
				if ( ++calls === 2 ) {
					throw new Error( 'Network failed' );
				}
				return ids;
			},
			( items ) => {
				remaining = items;
			}
		),
		/Network failed/
	);
	assert.equal( remaining.length, 300 );
	assert.equal( remaining[ 0 ].id, 201 );
	const retried = [];
	await undoAccepted(
		remaining,
		async ( ids ) => {
			retried.push( ...ids );
			return ids;
		},
		( items ) => {
			remaining = items;
		}
	);
	assert.deepEqual(
		retried,
		accepted( 500 )
			.slice( 200 )
			.map( ( item ) => item.id )
	);
	assert.equal( remaining.length, 0 );
} );

test( 'partial authorization failures retain the omitted IDs and stop', async () => {
	let remaining = accepted( 201 );
	await assert.rejects(
		undoAccepted(
			remaining,
			async ( ids ) => ids.slice( 1 ),
			( items ) => {
				remaining = items;
			}
		),
		/ai_act_labels_undo_incomplete/
	);
	assert.deepEqual(
		remaining.map( ( item ) => item.id ),
		[ 1, 201 ]
	);
} );
