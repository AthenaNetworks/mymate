package poll

import (
	"encoding/hex"
	"strconv"
	"unicode/utf8"

	"github.com/AthenaNetworks/mymate/agent/internal/proto"
	"github.com/gosnmp/gosnmp"
)

// LLDP-MIB and the CDP cache, for link suggestions (#85). The server keeps the same list in
// App\Services\Neighbors\NeighborTables and does all the parsing; this file only reads them.
// Each table's first column is its key: when it's empty the rest of that table is skipped, which
// keeps a discovery pass cheap on the many devices with no LLDP or CDP.
var neighborTables = [][]string{
	{
		"1.0.8802.1.1.2.1.4.1.1.5", // lldpRemChassisId
		"1.0.8802.1.1.2.1.4.1.1.9", // lldpRemSysName
		"1.0.8802.1.1.2.1.4.1.1.7", // lldpRemPortId
		"1.0.8802.1.1.2.1.4.1.1.6", // lldpRemPortIdSubtype
		"1.0.8802.1.1.2.1.4.1.1.8", // lldpRemPortDesc
		"1.0.8802.1.1.2.1.4.2.1.3", // lldpRemManAddrIfSubtype (the address is in the index)
		"1.0.8802.1.1.2.1.3.7.1.3", // lldpLocPortId
		"1.0.8802.1.1.2.1.3.7.1.4", // lldpLocPortDesc
	},
	{
		"1.3.6.1.4.1.9.9.23.1.2.1.1.6", // cdpCacheDeviceId
		"1.3.6.1.4.1.9.9.23.1.2.1.1.7", // cdpCacheDevicePort
		"1.3.6.1.4.1.9.9.23.1.2.1.1.3", // cdpCacheAddressType
		"1.3.6.1.4.1.9.9.23.1.2.1.1.4", // cdpCacheAddress
	},
}

// lldpLocChassisId: the device's own chassis id, so others' neighbour entries can be matched to it.
const oidLldpLocChassisID = "1.0.8802.1.1.2.1.3.2.0"

// walkNeighbors reads the neighbour tables. Nil when the device has neither LLDP nor CDP and no
// chassis id; a walk error stops it there (the device went quiet) with whatever it had.
func walkNeighbors(g *gosnmp.GoSNMP) *proto.NeighborTables {
	return readNeighbors(g.Get, g.WalkAll)
}

func readNeighbors(get func([]string) (*gosnmp.SnmpPacket, error), walkAll func(string) ([]gosnmp.SnmpPDU, error)) *proto.NeighborTables {
	nt := &proto.NeighborTables{Walks: map[string]map[string]string{}, Scalars: map[string]string{}}
walk:
	for _, columns := range neighborTables {
		for i, oid := range columns {
			pdus, err := walkAll(oid)
			if err != nil {
				break walk
			}
			if len(pdus) == 0 && i == 0 {
				break
			}
			rows := map[string]string{}
			for _, pdu := range pdus {
				if v, ok := rawValue(pdu); ok {
					rows[suffix(oid, pdu.Name)] = v
				}
			}
			if len(rows) > 0 {
				nt.Walks[oid] = rows
			}
		}
	}
	if res, err := get([]string{oidLldpLocChassisID}); err == nil && res != nil {
		for _, v := range res.Variables {
			if s, ok := rawValue(v); ok && s != "" {
				nt.Scalars[oidLldpLocChassisID] = s
			}
		}
	}
	if len(nt.Walks) == 0 && len(nt.Scalars) == 0 {
		return nil
	}
	return nt
}

// rawValue renders a PDU for the server: text as-is, binary octets as "hex:<hex>", numbers in
// decimal. False for the no-such-object kinds.
func rawValue(pdu gosnmp.SnmpPDU) (string, bool) {
	switch pdu.Type {
	case gosnmp.Null, gosnmp.NoSuchObject, gosnmp.NoSuchInstance, gosnmp.EndOfMibView:
		return "", false
	case gosnmp.OctetString:
		b, _ := pdu.Value.([]byte)
		if printable(b) {
			return string(b), true
		}
		return "hex:" + hex.EncodeToString(b), true
	}
	if n := gosnmp.ToBigInt(pdu.Value); n != nil {
		return strconv.FormatInt(n.Int64(), 10), true
	}
	return "", false
}

// printable: valid UTF-8 with no control characters other than tab/newline/CR.
func printable(b []byte) bool {
	if !utf8.Valid(b) {
		return false
	}
	for _, c := range b {
		if c < 0x20 && c != '\t' && c != '\n' && c != '\r' || c == 0x7f {
			return false
		}
	}
	return true
}
