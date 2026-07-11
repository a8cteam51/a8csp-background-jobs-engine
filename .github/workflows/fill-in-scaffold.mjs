import { statSync } from 'fs';
import { readdir, readFile } from 'fs/promises';
import { writeFile } from 'fs/promises';
import { join as joinPath } from 'path';
import process from 'process';

// Approximates @wordpress/e2e-test-utils-playwright's runtime paramCase() conversion from a plugin Name header to its slug.
const toKebabCase = ( str ) => str.toLowerCase().replace( /[^a-z0-9]+/g, '-' ).replace( /^-+|-+$/g, '' );

const repository = JSON.parse( process.argv[2] );
const skip_dirs = [ '.github', '.git' ];

/**
 * @param {string} dirPath
 * @param {(filePath: string) => Promise<void>} callback
 */
const traverseDirectory = async ( dirPath, callback ) => {
	if ( skip_dirs.includes( dirPath ) ) {
		console.log( 'Skipping %s', dirPath );
		return;
	}
	console.log( 'Traversing %s', dirPath );

	const files = await readdir( dirPath );
	for ( const file of files ) {
		const filePath = joinPath( dirPath, file );

		if ( statSync( filePath ).isFile() ) {
			await callback( filePath );
		} else {
			await traverseDirectory( filePath, callback );
		}
	}
};

/**
 * Renders filePath's scaffold placeholders (README.md gets EXAMPLE_REPO_* substitutions;
 * every other file gets the A8CSP_TEMPLATE_* identifier substitutions) and overwrites it in place
 * if anything changed.
 * @param {string} filePath
 */
const buildTemplate = async ( filePath ) => {
	console.log( 'Building %s', filePath );

	const templateFile   = await readFile( filePath, 'utf-8' );
	let renderedTemplate = templateFile, replacements;

	const title = repository.custom_properties['human-title'];
	if ( 'README.md' === filePath ) {
		replacements = {
			'EXAMPLE_REPO_NAME': title,
			'EXAMPLE_REPO_DESCRIPTION': repository.description ?? '',
		};
	} else {
		replacements = {
			'A8CSP Template Plugin': title,
			'A template for A8C Special Projects plugins.': repository.description ?? '',
			'a8csp/plugin-template': 'a8csp/' + repository.name,
			// The entry file's own name (fill-in-scaffold.yml already renamed it to
			// "$REPO_NAME.php" by this point) must resolve to the repo-name rule, not the
			// kebab-title rule below it — ordered first so this more specific, ".php"-suffixed
			// match consumes the substring before the bare kebab-title key can.
			'a8csp-template-plugin.php': repository.name + '.php',
			'a8csp-template-plugin': toKebabCase( title ),
			'a8csp-plugin-template': repository.name,
			// Matches the JSON-escaped namespace form composer.json's psr-4 autoload keys carry on disk;
			// the raw namespace value is escaped by the .json rendering path.
			'A8C\\\\SpecialProjects\\\\Template': 'A8C\\SpecialProjects\\' + title.replaceAll( ' ', '' ).replace( 'A8CSP', '' ),
			'A8C\\SpecialProjects\\Template': 'A8C\\SpecialProjects\\' + title.replaceAll( ' ', '' ).replace( 'A8CSP', '' ),
			'A8C\\SpecialProjects\\\\Template': 'A8C\\SpecialProjects\\\\' + title.replaceAll( ' ', '' ).replace( 'A8CSP', '' ),
			'a8csp_template': repository.custom_properties['php-globals-short-prefix'],
			'A8CSP_TEMPLATE': repository.custom_properties['php-globals-short-prefix'].toUpperCase(),
		};
	}

	for ( const [ key, value ] of Object.entries( replacements ) ) {
		// Substitution values land inside JSON string literals, so quotes/backslashes in free-text
		// repository metadata must be escaped to keep the document valid.
		const renderedValue = filePath.endsWith( '.json' ) ? JSON.stringify( value ).slice( 1, -1 ) : value;
		renderedTemplate = renderedTemplate.replaceAll( key, renderedValue );
	}

	if ( filePath.endsWith( '.php' ) ) {
		// PHP files never need trailing whitespace; stripping it prevents empty descriptions from leaving phpcs-failing blank lines.
		renderedTemplate = renderedTemplate.replace( /[ \t]+$/gm, '' );
	}

	if ( renderedTemplate !== templateFile ) {
		console.log( 'Changes were made. Overwriting file.' );
		await writeFile( filePath, renderedTemplate );
	}
};

await traverseDirectory( '.', buildTemplate );
