#!/usr/bin/python
# -*- coding: utf-8 -*-

import os, math, MySQLdb, datetime

def main():
	printGreeting()
	path = getFilePath()
	metafiles, jp2 = traverseDirectory(path)

	print "Found " + str(len(metafiles)) + " regular images and " + str(len(jp2)) + " jp2 images."

	images = processMetaFiles(metafiles)
	dbname, dbuser, dbpass = getDBInfo()
	storageMethod = getStorageReqs()

	db = MySQLdb.connect(host = "localhost", db = dbname, user = dbuser, passwd = dbpass)
	cursor = db.cursor()

	if(len(jp2) > 0):
		processJPEG2000Images(jp2, cursor)

	if(len(images) > 0):
		processImages(images, storageMethod, cursor)

	print "Finished!"

def traverseDirectory (path):
	''' Traverses file-tree starting with the specified path and builds a
		list of meta-files representing the available images'''
	images = []
	jp2 = []

	for child in os.listdir(path):
		node = os.path.join(path, child)
		if os.path.isdir(node):
			newImgs, newJP2 = traverseDirectory(node)
			images.extend(newImgs)
			jp2.extend(newJP2)
		else:
			if node[-4:] == "meta":
				images.append(node[:-5])
			elif node[-3:] == "jp2":
				jp2.append(node[:-4])

	return images, jp2

def processMetaFiles (meta):
	'''Takes a list of meta files and sorts them into a list of "images"
	   each of which may contain multiple zoom levels.'''
	images = {}
	for m in meta:
		dir, file = os.path.split(m)

		#Use the entire filepath excluding the zoomlevel as a key
		image = os.path.join(dir, file[:-3])
		zoomlevel = file[-2:]

		#Store each of the zoom-levels
		if not images.has_key(image):
			images[image] = []

		images[image].append(zoomlevel)

	return images

def processJPEG2000Images (images, cursor):
	''' Processes a collection of JPEG2000 Images. '''
	measurementIds = getMeasurementIds(cursor)

	for img in images:
		dir, file = os.path.split(img)

		year = int(file[:4])
		mon  = int(file[5:7])
		day  = int(file[8:10])
		hour = int(file[11:13])
		min  = int(file[13:15])
		sec  = int(file[15:17])
		obs  = file[18:22]
		inst = file[23:26]
		det  = file[27:30]
		meas = file[31:34]

		print "Processing " + file + ".jp2..."

		filetype = "jp2"

		date = datetime.datetime(year, mon, day, hour, min, sec)
		query = "INSERT INTO image VALUES(NULL, %d, '%s', '%s')" % (measurementIds[det + meas], date, filetype)

		try:
			cursor.execute(query)

		except MySQLdb.Error, e:
			print "Error: " + e.args[1]




def processImages (images, storageMethod, cursor):
	''' Iterates through a collection of images, each of which keeps
		a list of all of the zoom-levels for which data exists, and populates
		the image and tile tables of the database schema provided. '''
	import glob
	from urlparse import urljoin

	measurementIds = getMeasurementIds(cursor)
	id = getStartingId(cursor);
	
	if storageMethod != 'database':
		baseurl = raw_input("Please enter the root directory where the tiles are located [http://localhost/tiles]:")
		if baseurl == "":
			baseurl = "http://localhost/tiles/"
		if not baseurl[-1] == "/":
			baseurl += "/"

	for img in images.keys():
		dir, file = os.path.split(img)

		year = int(file[:4])
		mon  = int(file[5:7])
		day  = int(file[8:10])
		hour = int(file[11:13])
		min  = int(file[13:15])
		sec  = int(file[15:17])
		obs  = file[18:22]
		inst = file[23:26]
		det  = file[27:30]
		meas = file[31:34]

		zoomLevels = images[img]

		print "Processing " + file + "..."

		#Add images to the "image" table
		filetype = "png" if (inst == "LAS") else "jpg"

		date = datetime.datetime(year, mon, day, hour, min, sec)
		query = "INSERT INTO image VALUES(%d, %d, '%s', '%s')" % (id, measurementIds[det + meas], date, filetype)

		try:
			cursor.execute(query)

		except MySQLdb.Error, e:
			print "Error: " + e.args[1]

		#Add entries to the "tile" table
		'''
		for x in range(0, getNumTiles(zoom)/2):
			for y in range(0, getNumTiles(zoom)/2):
				if os.path.exists(os.path.join(dir, file, ):
					print "Tile exists!"
				else:
					print "Tile DOES NOT exist!"
		'''


		for tile in glob.glob(img + "*"):
			if (tile[-4:] != "meta") and (tile[-3:] != "jp2"):
				tile_dir, tile_name = os.path.split(tile)
				zoom = tile_name[35:37]
				x = tile_name[38:41]
				y = tile_name[42:45]
				blob = open(tile, 'rb').read()

				print "Processing " + tile + "..."

				if storageMethod != 'database':
					filepath = str(year) + "/" + str(mon).rjust(2, "0") + "/" + str(day).rjust(2, "0") + "/" + str(hour).rjust(2, "0") + "/" \
					+ str(obs) + "/" + str(inst) + "/" + str(det) + "/" + str(meas) + "/"
					filepath += file + "_" + zoom + "_" + x + "_" + y + "." + filetype
					url = urljoin(baseurl, filepath)

				# SQL statement depends on where the tile images should be stored
				if storageMethod == "database":
					sql = "INSERT INTO tile VALUES(%d, %d, %d, %d, null, '%s')" % (id, int(x), int(y), int(zoom), MySQLdb.escape_string(blob))

				elif storageMethod == "filesystem":
					sql = "INSERT INTO tile VALUES(%d, %d, %d, %d, '%s', null)" % (id, int(x), int(y), int(zoom), url)

				elif storageMethod == "both":
					sql = "INSERT INTO tile VALUES(%d, %d, %d, %d, '%s', '%s')" % (id, int(x), int(y), int(zoom), url, MySQLdb.escape_string(blob))

				#print "Working with tile: " + tile + ", zoomlevel: " + str(zoom)

				try:
					cursor.execute(sql)

				except MySQLdb.Error, e:
					print "Error: " + e.args[1]

		id += 1

def getMeasurementIds(cursor):
	''' Returns an associative array of the measurement ID's used for the
		measurement types supported. Uses the combination of detector and
		measurement (e.g. 195EIT) as a hash key.'''

	query = "SELECT  detector.abbreviation as detector, measurement.abbreviation as measurement, measurement.id as measurementId FROM measurement LEFT JOIN detector on detectorId = detector.id"

	try:
		cursor.execute(query)
		result_array = cursor.fetchall()
	except MySQLdb.Error, e:
		print "Error: " + e.args[1]

	measurements = {}

	# Note: By convention, "0"'s are added in front of any identifier < full size. (e.g. "C2" -> "0C2").
	for meas in result_array:
		#measurements[meas[0].rjust(3, "0") + meas[1].rjust(3, "0")] = meas[2]
		measurements[meas[0] + meas[1]] = meas[2]

	return measurements

def getStartingId(cursor):
	''' Returns the highest Id number found in the database before any new
		images are added. The id numbers can then start at the next highest
		number. This makes it easier to keep track of the image id when
		adding the tiles to the database later.'''

	query = "SELECT id FROM image ORDER BY id  DESC LIMIT 1"

	try:
		cursor.execute(query)
		result = cursor.fetchone()
	except MySQLdb.Error, e:
		print "Error: " + e.args[1]

	return (int(result[0]) + 1) if result else 0

def printGreeting():
	''' Prints a greeting to the user'''
	os.system("clear")

	print "===================================================================="
	print "= HelioViewer Database Population Script 0.95                      ="
	print "= By: Keith Hughitt, September 30, 2008                            ="
	print "=                                                                  ="
	print "= This script processes raw tile images, and inserts them into a   ="
	print "= database, along with their relevant information.                 ="
	print "=                                                                  ="
	print "= The script requires several pieces of information to function:   ="
	print "=   (1) The location of a directory containing tiled images.       ="
	print "=   (2) The name of the database schema to populate.               ="
	print "=   (3) The name of the database user with appropriate access.     ="
	print "=   (4) The password for the specified database user.              ="
	print "===================================================================="

def getFilePath():
	''' Prompts the user for the directory information '''

	path = raw_input("Root directory: ")
	while not os.path.isdir(path):
		print "That is not a valid directory! Please try again."
		path = raw_input("Root directory: ")

	return path

def getDBInfo():
	''' Prompts the user for the required database information '''
	dbname = raw_input("Database name: ")
	dbuser = raw_input("Database user: ")
	dbpass = raw_input("Database password: ")
	return dbname, dbuser, dbpass

def getStorageReqs():
	''' Prompts the user to input the desired tile storage method '''
	''' Note: meta-information is always stored in a database.'''
	def printChoices():
			print "Where would you like to store the tile images?"
			print "    (1) Filesystem"
			print "    (2) Database"
			print "    (3) Both"

	printChoices()
	choice = int(raw_input(":"))

	while (not ((choice == 1) or (choice == 2) or (choice == 3))):
		print "That is not a valid selection!"
		printChoices()
		print "Please enter '1', '2', or '3'"
		choice = int(raw_input(":"))

	if choice == 1:
		storage = "filesystem"
	elif choice == 2:
		storage = "database"
	elif choice == 3:
		storage = "both"

	return storage

def getNumTiles (zoomLevel):
	''' Returns the number of tiles expected for a given zoom-level '''

	#INCORRECT
	#Each image above zoom-level 9 consists of exactly 4 tiles. For zoom Levels
	#below that, the number of tiles is 4^2, 5^2, etc.
	#return int(4 if zoomLevel >= 10 else math.pow((13 - zoomLevel), 2))

	#CORRECT
	#Each image above zoom-level 9 consists of exactly 4 tiles. For zoom Levels
	#below that, the number of tiles is 4^2, 4^3, etc.
	return int(4 if zoomLevel >= 10 else math.pow(4, 11 - zoomLevel))

def compact(seq):
	'''Removes duplicate entries from a list.
	   From http://www.peterbe.com/plog/uniqifiers-benchmark'''
	seen = set()
	return [ x for x in seq if x not in seen and not seen.add(x)]

if __name__ == '__main__':
	main()
