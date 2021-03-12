release: ## Updates the changelog from Git history and tag a final version
	@npx npx standard-version -t ""

prerelease: ## Updates the changelog from Git history and tag a RC version
	@npx npx standard-version -t "" -p
