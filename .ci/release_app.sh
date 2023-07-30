#! /bin/bash

set -u
set -e

if [ -z ${1} ]; then
	echo "Release version (arg1) not set !"
	exit 1;
fi

SRC_DIR=`dirname $0`"/.."
RELEASE_VERSION=${1}
echo "Release version set to ${RELEASE_VERSION}"

sed -ri 's/(.*)<version>(.+)<\/version>/\1<version>'${RELEASE_VERSION}'<\/version>/g' ${SRC_DIR}/appinfo/info.xml
git commit -am "Release "${RELEASE_VERSION}
git tag ${RELEASE_VERSION}
git push
git push --tags
# Wait a second for Github to ingest our data
sleep 1
cd /tmp
rm -Rf epubviewer-packaging && mkdir epubviewer-packaging && cd epubviewer-packaging

# Download the git file from github
wget https://github.com/devnoname120/epubviewer/archive/${RELEASE_VERSION}.tar.gz
tar xzf ${RELEASE_VERSION}.tar.gz
mv epubviewer-${RELEASE_VERSION} epubviewer

# Drop unneeded files
rm -Rf \
	epubviewer/js/devel \
	epubviewer/gulpfile.js \
	epubviewer/package.json \
	epubviewer/.ci \
	epubviewer/.tx \
	epubviewer/doc

tar cfz epubviewer-${RELEASE_VERSION}.tar.gz epubviewer
echo "Release version "${RELEASE_VERSION}" is now ready."
