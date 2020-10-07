import React, { Fragment } from 'react';

export default function save() {
	const config = {}

	return (
		<Fragment>
			<script src="https://js.chargebee.com/v2/chargebee.js" />
			<script dangerouslySetInnerHTML={{
				__html: `var GreensJoinFormConfig = ${JSON.stringify(config)}`
			}}
			/>
			<div id="join-form" />
		</Fragment>
	)
}
