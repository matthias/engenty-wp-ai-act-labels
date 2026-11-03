/**
 * Undo accepted labels in API-sized batches, retaining unfinished IDs for a retry.
 * @param accepted
 * @param apply
 * @param onRemaining
 */
export async function undoAccepted( accepted, apply, onRemaining ) {
	let remaining = [ ...accepted ];
	while ( remaining.length ) {
		const ids = remaining.slice( 0, 200 ).map( ( item ) => item.id );
		const done = new Set( await apply( ids ) );
		const undone = new Set( ids.filter( ( id ) => done.has( id ) ) );
		remaining = remaining.filter( ( item ) => ! undone.has( item.id ) );
		onRemaining( remaining );
		if ( undone.size !== ids.length ) {
			throw new Error( 'ai_act_labels_undo_incomplete' );
		}
	}
}
