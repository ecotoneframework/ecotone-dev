import csv
import pathlib
import statistics
import sys
import xml.etree.ElementTree as ET

writer = csv.writer(sys.stdout)
writer.writerow(['file', 'subject', 'revs', 'iterations', 'mean_us', 'rstdev_percent', 'peak_bytes', 'iteration_us'])
for argument in sys.argv[1:]:
    path = pathlib.Path(argument)
    tree = ET.parse(path)
    for subject in tree.findall('.//subject'):
        for variant in subject.findall('variant'):
            samples = [float(item.attrib['time-net']) / int(variant.attrib['revs']) for item in variant.findall('iteration')]
            if not samples:
                continue
            mean = statistics.mean(samples)
            deviation = statistics.pstdev(samples) / mean * 100
            peak = max(int(item.attrib['mem-peak']) for item in variant.findall('iteration'))
            writer.writerow([path.name, subject.attrib['name'], variant.attrib['revs'], len(samples),
                             round(mean, 3), round(deviation, 3), peak, ';'.join(map(str, samples))])
