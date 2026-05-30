/**
 * isoft-fm-foundation/download-list block — Phase 3
 */
import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import Edit from './edit';

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null, // Server-side rendered
} );
