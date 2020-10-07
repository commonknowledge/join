
export const usePostResource = <Params, Result = {}>(resource: string) => {
	return async (data: Params): Promise<Result> => {
		const endpoint = '/join/v1' + resource
		const res = await fetch('/?rest_route=' + endpoint, {
			method: 'POST',
			headers: {
				'content-type': 'application/json',
				'accept': 'application/json'
			},
			body: JSON.stringify(data)
		})

		if (!res.ok) {
			throw Error(await res.text())
		}

		return res.json()
	}
}
