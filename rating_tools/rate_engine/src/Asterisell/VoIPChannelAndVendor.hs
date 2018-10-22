{-# Language BangPatterns, OverloadedStrings, ScopedTypeVariables  #-}
{-# LANGUAGE QuasiQuotes, TypeSynonymInstances, FlexibleInstances, DeriveGeneric, DeriveAnyClass #-}

-- SPDX-License-Identifier: GPL-3.0-or-later

-- | Import info about vendors, and channels.
--
module Asterisell.VoIPChannelAndVendor(
  VendorId,
  Vendors,
  VendorCode,
  ChannelTypeId,
  ChannelTypeCode,
  ChannelTypes,
  ChannelDomains,
  IsSuffixChannel,
  IsPrefixChannel,
  FromTime,
  ToTime,
  channelDomains_match,
  vendors_load,
  channelDomains_load,
  channelTypes_load
) where

import Asterisell.Trie
import Asterisell.Utils
import Asterisell.DB
import Asterisell.Error

import Control.Applicative ((<$>), (<|>), (<*>), (<*), (*>), many)
import Control.Monad (void)
import qualified Data.Text as Text
import qualified Data.ByteString as BS
import Data.Map.Strict as Map
import Data.Maybe
import Data.List as List
import Data.Time.LocalTime

import GHC.Generics
import Control.DeepSeq
import Database.MySQL.Base as DB
import qualified Database.MySQL.Protocol.Escape as DB
import Database.MySQL.Protocol.MySQLValue
import qualified System.IO.Streams as S
import qualified System.IO.Streams.Text as S
import qualified System.IO.Streams.Combinators as S
import Text.Heredoc
import Control.Exception.Safe (catch, catchAny, onException, finally, handleAny, bracket
                              , SomeException, throwIO, throw, Exception, MonadMask
                              , withException, displayException)
type VendorId = Int
type VendorCode = Text.Text

type Vendors = Map.Map VendorCode VendorId

type ChannelTypeId = Int
type ChannelTypeCode = Text.Text

type ChannelTypes = Map.Map ChannelTypeCode ChannelTypeId

type IsSuffixChannel = Bool
type IsPrefixChannel = Bool
type FromTime = LocalTime
type ToTime = Maybe LocalTime

type ChannelDomainEntry
  = (VendorId, ChannelTypeId, IsPrefixChannel, FromTime, ToTime)

type ChannelDomains = Trie [ChannelDomainEntry]

vendors_load :: DB.MySQLConn -> Bool -> IO Vendors
vendors_load conn isDebugMode = do
  let q1 = [str| SELECT
               |   id
               | , internal_name
               | FROM ar_vendor
               | WHERE internal_name IS NOT NULL
               |]

  (_, inS) <- DB.query_ conn q1
  S.foldM importVendor Map.empty inS

 where

  importVendor
    map1
    [ id'
    , internal_name'
    ] = do

        let id = fromDBInt id'
        internal_name <- nn "vendor" id fromDBText internal_name'
        return $ Map.insert internal_name id map1

  importVendor _ _ = throwIO $ AsterisellException "err 1602 in code: unexpected DB format for ar_vendor table"

channelTypes_load :: DB.MySQLConn -> Bool -> IO ChannelTypes
channelTypes_load conn isDebugMode = do
  let q1 = [str| SELECT
               |   id
               | , internal_name
               | FROM ar_communication_channel_type
               | WHERE internal_name IS NOT NULL
               |]

  (_, inS) <- DB.query_ conn q1
  S.foldM importChannel Map.empty inS

 where

  importChannel
    map1
    [ id'
    , internal_name'
    ] = do

        let id = fromDBInt id'
        internal_name <- nn "ar_communication_channel_type" id fromDBText internal_name'
        return $ Map.insert internal_name id map1

  importChannel _ _ = throwIO $ AsterisellException "err 1602 in code: unexpected DB format for ar_channel_type table"

channelDomains_load :: DB.MySQLConn -> Bool -> IO ChannelDomains
channelDomains_load conn isDebugMode = do
  let q1 = [str| SELECT
               |   id
               | , internal_name
               | , ar_vendor_id
               | , ar_communication_channel_type_id
               | , domain
               | , is_prefix
               | , is_suffix
               | , `from`
               | , `to`
               | FROM ar_vendor_domain
               |]

  (_, inS) <- DB.query_ conn q1
  S.foldM importVendorDomain trie_empty inS

 where

  importVendorDomain
    map1
    [ id'
    , internal_name'
    , ar_vendor_id'
    , ar_communication_channel_type_id'
    , domain'
    , is_prefix'
    , is_suffix'
    , from_d'
    , to_d'
    ] = do
           let id = fromDBInt id'
           let internal_name
                 = case fromMaybeDBValue fromDBText internal_name' of
                     Nothing -> ""
                     Just n -> n
           ar_vendor_id <- nn "vendor_domain" id fromDBInt ar_vendor_id'
           ar_communication_channel_type_id <- nn "vendor_domain" id fromDBInt ar_communication_channel_type_id'
           domain'' <- nn "vendor_domain" id fromDBByteString domain'
           is_prefix <- nn "vendor_domain" id fromDBBool is_prefix'
           is_suffix <- nn "vendor_domain" id fromDBBool is_suffix'
           from_d <- nn "vendor_domain" id fromDBLocalTime from_d'
           let to_d = fromMaybeDBValue fromDBLocalTime to_d'

           let domain
                 = case is_prefix of
                     True -> BS.append domain'' "*"
                     False -> domain''

           case is_suffix of
             True -> (throwIO $ AsterisellException $ "Suffix Vendor Domains are not any more supported, in table ar_vendor_domain, in id " ++ show id)
             False -> return ()

           let value = (ar_vendor_id, ar_communication_channel_type_id, is_prefix, from_d, to_d)

           return $ trie_insertExtensionWith map1 domain [value] (++)

  importVendorDomain _ _ = throwIO $ AsterisellException "err 1608 in code: unexpected DB format for ar_vendor_domain"

channelDomains_match :: ChannelDomains -> BS.ByteString -> LocalTime -> [(VendorId, ChannelTypeId)]
channelDomains_match trie domain date
  = candidateResults
 where

  mySearch d
    = case trie_match trie d of
        Nothing -> []
        Just (_, _, r) -> r

  insideDate (_, _, _, fromTime, maybeToTime)
    = (date >= fromTime) && (isNothing maybeToTime || date < (fromJust maybeToTime))

  toResult (vendorId, channelTypeId, _, _, _)
    = (vendorId, channelTypeId)

  candidateResults
    =  List.map toResult $ List.filter insideDate (mySearch domain)
